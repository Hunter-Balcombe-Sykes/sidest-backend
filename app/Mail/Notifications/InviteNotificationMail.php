<?php

namespace App\Mail\Notifications;

use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// V2: Sends invite notifications (staff invites, affiliate invites) using the Notification model and the invites template.
class InviteNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Notification $notification) {}

    public function build(): self
    {
        return $this
            ->subject($this->notification->title)
            ->view('emails.notifications.invites');
    }
}
