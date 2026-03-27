<?php

namespace App\Jobs\Notifications;

use App\Mail\Notifications\AnalyticsMilestoneMail;
use App\Mail\Notifications\AnalyticsWeeklyMail;
use App\Mail\Notifications\BrandLinkMail;
use App\Mail\Notifications\BrandStatusMail;
use App\Mail\Notifications\CatalogChangeMail;
use App\Mail\Notifications\CommissionNotificationMail;
use App\Mail\Notifications\IntegrationNotificationMail;
use App\Mail\Notifications\InviteNotificationMail;
use App\Mail\Notifications\PayoutNotificationMail;
use App\Mail\Notifications\ProfileTaskMail;
use App\Mail\Notifications\SubscriptionMail;
use App\Models\Core\Notifications\Notification;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendTransactionalNotificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly string $notificationId,
        public readonly string $category,
        public readonly string $professionalId,
    ) {}

    public function handle(): void
    {
        if (! config('comet.notifications.email_enabled', false)) {
            return;
        }

        if (! NotificationPublisher::resolveEmailEnabled($this->professionalId, $this->category)) {
            return;
        }

        $notification = Notification::query()->find($this->notificationId);
        if (! $notification instanceof Notification) {
            return;
        }

        $email = DB::table('core.professionals')
            ->where('id', $this->professionalId)
            ->value('primary_email');

        if (! $email) {
            return;
        }

        $mailable = $this->buildMailable($notification);
        if ($mailable === null) {
            return;
        }

        Mail::to($email)->send($mailable);
    }

    private function buildMailable(Notification $notification): ?\Illuminate\Mail\Mailable
    {
        return match ($this->category) {
            'invites'              => new InviteNotificationMail($notification),
            'commissions'          => new CommissionNotificationMail($notification),
            'payouts'              => new PayoutNotificationMail($notification),
            'integrations'         => new IntegrationNotificationMail($notification),
            'analytics_weekly'     => new AnalyticsWeeklyMail($notification),
            'analytics_milestones' => new AnalyticsMilestoneMail($notification),
            'profile_tasks'        => new ProfileTaskMail($notification),
            'catalog_changes'      => new CatalogChangeMail($notification),
            'brand_status'         => new BrandStatusMail($notification),
            'subscriptions'        => new SubscriptionMail($notification),
            'brand_links'          => new BrandLinkMail($notification),
            default                => null,
        };
    }
}
