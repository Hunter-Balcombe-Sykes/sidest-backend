<?php

namespace App\Jobs\Notifications;

use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

// Fans out staff broadcast emails to all marketing-list subscribers using Bus::batch()
// so each sub-chunk of jobs shares one Redis pipeline write instead of one write per job —
// the marketing list grows unboundedly with sign-ups, so this is the urgent batch fix.
class SendStaffBroadcastEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 120;

    // Bound batch size so any one Redis pipeline write stays predictable.
    // Shared with FanOutBrandStatusNotificationJob — keep in sync if changed.
    private const BATCH_CHUNK_SIZE = 200;

    public function __construct(
        public string $notificationId,
        public string $listKey = 'sidest_updates'
    ) {
        // Coordinator job: long chunkById walk over EmailSubscription. Keep it off
        // the default queue so a large broadcast doesn't back-pressure unrelated work.
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $notification = Notification::query()->find($this->notificationId);
        if (! $notification) {
            return;
        }

        EmailSubscription::query()
            ->whereNull('professional_id')
            ->where('list_key', $this->listKey)
            ->where('status', 'subscribed')
            ->orderBy('id')
            ->chunkById(500, function ($subs) use ($notification) {
                $jobs = $subs->map(fn ($sub) => new SendStaffBroadcastEmailToSubscriberJob(
                    $notification->id,
                    $sub->id,
                ))->all();

                // One Redis pipeline write per batch vs. one per job if dispatched
                // individually. allowFailures() preserves the per-job retry semantics
                // SendStaffBroadcastEmailToSubscriberJob's $tries=3 promises — without it,
                // a single failure cancels remaining (still-pending) jobs in the batch.
                foreach (array_chunk($jobs, self::BATCH_CHUNK_SIZE) as $chunk) {
                    $batch = Bus::batch($chunk)
                        ->onQueue('mail')
                        ->name('staff-broadcast:'.$notification->id)
                        ->allowFailures()
                        ->dispatch();

                    Log::info('Staff broadcast batch dispatched', [
                        'batch_id' => $batch->id,
                        'notification_id' => $notification->id,
                        'list_key' => $this->listKey,
                        'job_count' => count($chunk),
                    ]);
                }
            });
    }

    public function failed(\Throwable $e): void
    {
        // Forward to Nightwatch so the dropped broadcast is observable by notification_id.
        report($e);

        Log::error('Staff broadcast fan-out permanently failed', [
            'notification_id' => $this->notificationId,
            'list_key' => $this->listKey,
            'message' => $e->getMessage(),
        ]);
    }
}
