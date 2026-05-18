<?php

namespace App\Mail\Auth;

use App\Mail\BaseTransactionalMail;

class EmailConfirmMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $displayName,
        public readonly string $verifyUrl,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Confirm your Partna email')
            ->view('emails.auth.email-confirm');
    }
}
