<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Common ancestor for every Partna transactional email.
 *
 * Concrete subclasses are responsible for setting their own subject + view +
 * payload via build(); this base only enforces shared envelope concerns
 * (from address, reply-to, headers) so the entire pipeline stays consistent.
 */
abstract class BaseTransactionalMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Apply shared envelope defaults before any subclass build() chain runs.
     */
    public function buildEnvelope(): self
    {
        return $this
            ->from(
                config('mail.from.address', 'hello@partna.au'),
                config('mail.from.name', 'Partna')
            )
            ->replyTo(
                config('mail.from.address', 'hello@partna.au'),
                config('mail.from.name', 'Partna')
            )
            ->withSymfonyMessage(function ($message): void {
                // Identify the pipeline for downstream analytics + bounce attribution.
                $message->getHeaders()->addTextHeader('X-Partna-Pipeline', 'transactional');
            });
    }
}
