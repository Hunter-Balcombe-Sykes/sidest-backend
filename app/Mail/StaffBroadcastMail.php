<?php

namespace App\Mail;

use App\Models\Core\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StaffBroadcastMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Notification $notification,
        public string $unsubscribeUrl
    ) {}

    public function build()
    {
        return $this
            ->subject($this->notification->title)
            ->view('emails.staff_broadcast');
    }
}
