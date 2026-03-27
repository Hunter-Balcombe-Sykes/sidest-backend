<?php

namespace App\Mail\Notifications;

use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProfileTaskMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Notification $notification) {}

    public function build(): self
    {
        return $this
            ->subject($this->notification->title)
            ->view('emails.notifications.profile_tasks');
    }
}
