<?php

namespace App\Mail\Notifications;

use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// V2: Sends analytics milestone achievement emails using the Notification model for subject and the analytics_milestones template.
class AnalyticsMilestoneMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Notification $notification) {}

    public function build(): self
    {
        return $this
            ->subject($this->notification->title)
            ->view('emails.notifications.analytics_milestones');
    }
}
