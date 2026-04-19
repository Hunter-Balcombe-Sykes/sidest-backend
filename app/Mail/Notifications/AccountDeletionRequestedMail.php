<?php

namespace App\Mail\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// V2: Confirmation email sent when a professional requests account deletion.
// Contains a token-bearing link that expires in 24 hours.
class AccountDeletionRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $displayName,
        public readonly string $confirmationUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Confirm your account deletion request')
            ->view('emails.account.deletion-requested');
    }
}
