<?php

namespace App\Mail\Gdpr;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// V2: Emails the recipient (professional or admin staff) a 7-day signed R2
// URL pointing at the data-export zip. Two visual variants — self-service is
// addressed to the professional; staff variant carries a PII-handling banner.
class ProfessionalDataExportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $signedUrl,
        public string $professionalHandle,
        public string $sendTo,
        public array $recordCounts,
    ) {}

    public function build(): static
    {
        $subject = $this->sendTo === 'staff'
            ? "Side St data export — {$this->professionalHandle}"
            : 'Your Side St data export is ready';

        return $this
            ->subject($subject)
            ->view('emails.gdpr.professional-data-export', [
                'signedUrl' => $this->signedUrl,
                'professionalHandle' => $this->professionalHandle,
                'isStaff' => $this->sendTo === 'staff',
                'totalRecords' => array_sum($this->recordCounts),
                'ttlDays' => (int) config('sidest.gdpr.signed_url_ttl_days', 7),
            ]);
    }
}
