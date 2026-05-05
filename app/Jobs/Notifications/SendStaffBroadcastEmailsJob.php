<?php

namespace App\Jobs\Notifications;

use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Fans out staff broadcast emails to subscribers in 500-row batches.
class SendStaffBroadcastEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public string $notificationId,
        public string $listKey = 'sidest_updates'
    ) {}

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
                foreach ($subs as $sub) {
                    SendStaffBroadcastEmailToSubscriberJob::dispatch(
                        $notification->id,
                        $sub->id
                    )->onQueue('mail');
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
