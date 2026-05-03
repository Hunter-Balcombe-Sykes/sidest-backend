<?php

namespace App\Jobs\Notifications;

use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Weekly sales/commission rollup notification for all active professionals. Runs on scheduled cron.
class SendWeeklyAnalyticsNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(NotificationPublisher $publisher): void
    {
        $yearWeek = now()->format('o-W'); // ISO year + week number
        $weekStart = now()->subDays(7)->toDateString();
        $weekEnd = now()->subDay()->toDateString();

        DB::table('core.professionals')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(200, function ($professionals) use ($publisher, $yearWeek, $weekStart, $weekEnd): void {
                $ids = $professionals->pluck('id')->all();

                $metricsByPro = DB::table('analytics.professional_metrics_daily')
                    ->whereIn('affiliate_professional_id', $ids)
                    ->whereBetween('day', [$weekStart, $weekEnd])
                    ->select('affiliate_professional_id',
                        DB::raw('COALESCE(SUM(orders_count), 0) as orders'),
                        DB::raw('COALESCE(SUM(commission_accrued_cents), 0) as commission_cents'))
                    ->groupBy('affiliate_professional_id')
                    ->get()
                    ->keyBy('affiliate_professional_id');

                foreach ($professionals as $professional) {
                    try {
                        $metrics = $metricsByPro->get($professional->id);
                        $this->notifyProfessional($publisher, $professional, $metrics, $yearWeek);
                    } catch (\Throwable $e) {
                        Log::warning('Weekly analytics notification failed', [
                            'professional_id' => $professional->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function notifyProfessional(
        NotificationPublisher $publisher,
        object $professional,
        ?object $metrics,
        string $yearWeek,
    ): void {
        $orders = (int) ($metrics?->orders ?? 0);
        $commissionCents = (int) ($metrics?->commission_cents ?? 0);

        if ($orders === 0 && $commissionCents === 0) {
            return;
        }

        $body = "Last 7 days: {$orders} sale".($orders !== 1 ? 's' : '');
        if ($commissionCents > 0) {
            $commission = 'A$'.number_format($commissionCents / 100, 2);
            $body .= ", {$commission} in commissions";
        }
        $body .= '.';

        $publisher->publish(
            professionalId: $professional->id,
            frontendType: 'Info',
            category: 'analytics_weekly',
            title: 'Your weekly analytics',
            body: $body,
            dedupeKey: "analytics.weekly.{$professional->id}.{$yearWeek}",
            ctaUrl: '/account/store?section=analytics',
            retentionConfigKey: 'analytics_weekly',
        );
    }
}
