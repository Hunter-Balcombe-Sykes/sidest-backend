<?php

namespace App\Jobs\Notifications;

use App\Mail\StaffBroadcastMail;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Sends individual staff broadcast email, respecting unsubscribe preferences and subscriber status.
// Dispatched by SendStaffBroadcastEmailsJob — one job per recipient so failures isolate and retry independently.
class SendStaffBroadcastEmailToSubscriberJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $notificationId,
        public string $subscriptionId
    ) {}

    public function handle(): void
    {
        $notification = Notification::query()->find($this->notificationId);
        if (! $notification) {
            return;
        }

        $sub = EmailSubscription::query()->find($this->subscriptionId);
        if (! $sub) {
            return;
        }

        // Respect unsubscribes that happened after the broadcast was queued
        if ($sub->status !== 'subscribed') {
            return;
        }

        // Claim the send slot before touching the mailer — at-most-once delivery.
        // A crash between insert and send leaves a "sent" receipt for a never-sent
        // email, which is the correct bias for broadcast: losing one copy is better
        // than a subscriber receiving duplicates.
        $inserted = DB::table('notifications.broadcast_email_receipts')->insertOrIgnore([
            'notification_id' => $this->notificationId,
            'subscription_id' => $this->subscriptionId,
        ]);

        if ($inserted === 0) {
            return; // already delivered on a previous attempt
        }

        $unsubscribeUrl = route('public.unsubscribe', ['token' => $sub->unsubscribe_token]);

        Mail::to($sub->email)->send(
            new StaffBroadcastMail($notification, $unsubscribeUrl)
        );
    }

    public function failed(\Throwable $e): void
    {
        // Forward to Nightwatch so the failure is observable by notification_id.
        report($e);

        Log::error('Staff broadcast email permanently failed', [
            'notification_id' => $this->notificationId,
            'subscription_id' => $this->subscriptionId,
            'message' => $e->getMessage(),
        ]);
    }
}
