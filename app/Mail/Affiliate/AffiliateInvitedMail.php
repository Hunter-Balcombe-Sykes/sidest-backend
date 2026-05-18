<?php

namespace App\Mail\Affiliate;

use App\Mail\BaseTransactionalMail;

/**
 * Sent to the email on a BrandAffiliateInvite when the brand creates it.
 *
 * Replaces the previous behaviour where invites to brand-new email addresses
 * (no existing Partna account) silently sent nothing — the brand had to share
 * the link manually. Routed by BrandAffiliateInviteObserver::created.
 */
class AffiliateInvitedMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $recipientFirstName,
        public readonly string $brandName,
        public readonly string $acceptUrl,
        public readonly ?int $expiresInDays = null,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject("{$this->brandName} invited you to Partna")
            ->view('emails.affiliate.invited');
    }
}
