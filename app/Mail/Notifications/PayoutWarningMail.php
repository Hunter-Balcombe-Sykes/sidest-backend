<?php

namespace App\Mail\Notifications;

use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// Sends tiered payout grace-period warnings (T-30 / T-7 / T-1) via the unified
// NotificationPublisher pipeline. Subject is the Notification title; the view
// renders title/body/cta from the Notification model.
class PayoutWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Notification $notification) {}

    public function build(): self
    {
        return $this
            ->subject($this->notification->title)
            ->view('emails.notifications.payout_warnings');
    }
}
