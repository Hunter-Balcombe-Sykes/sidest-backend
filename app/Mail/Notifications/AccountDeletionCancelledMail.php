<?php

namespace App\Mail\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// V2: Sent when a professional cancels their pending deletion during the grace period.
class AccountDeletionCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $displayName,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Your account deletion has been cancelled')
            ->view('emails.account.deletion-cancelled');
    }
}
