<?php

namespace App\Mail\Notifications;

use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// V2: Sends brand affiliate link notifications (new links, link updates) using the Notification model and the brand_links template.
class BrandLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Notification $notification) {}

    public function build(): self
    {
        return $this
            ->subject($this->notification->title)
            ->view('emails.notifications.brand_links');
    }
}
