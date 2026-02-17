<?php

namespace App\Jobs\Notifications;

use App\Mail\StaffBroadcastMail;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Notifications\EmailSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendStaffBroadcastEmailToSubscriberJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $notificationId,
        public string $subscriptionId
    ) {}

    public function handle(): void
    {
        $notification = Notification::query()->find($this->notificationId);
        if (!$notification) return;

        $sub = EmailSubscription::query()->find($this->subscriptionId);
        if (!$sub) return;

        // Respect unsubscribes that happened after the broadcast was queued
        if ($sub->status !== 'subscribed') return;

        $unsubscribeUrl = route('public.unsubscribe', ['token' => $sub->unsubscribe_token]);

        Mail::to($sub->email)->send(
            new StaffBroadcastMail($notification, $unsubscribeUrl)
        );
    }
}
