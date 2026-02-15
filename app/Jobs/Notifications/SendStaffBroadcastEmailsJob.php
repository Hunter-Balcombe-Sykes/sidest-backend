<?php

namespace App\Jobs\Notifications;

use App\Mail\StaffBroadcastMail;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendStaffBroadcastEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $notificationId,
        public string $listKey = 'comet_updates'
    ) {}

    public function handle(): void
    {
        $notification = Notification::query()->find($this->notificationId);
        if (!$notification) return;

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
}
