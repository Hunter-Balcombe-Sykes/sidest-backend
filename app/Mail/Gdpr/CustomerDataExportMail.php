<?php

namespace App\Mail\Gdpr;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

// V2: Emails the merchant a JSON dump of a customer's stored data in response
// to Shopify `customers/data_request`. Merchant forwards to the requesting
// customer — Shopify's recommended pattern since the merchant has a verified
// identity channel to the customer and we do not.
class CustomerDataExportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $shopDomain,
        public string $customerEmail,
        public array $exportData,
    ) {}

    public function build(): static
    {
        return $this
            ->subject("GDPR customer data request for {$this->customerEmail} — {$this->shopDomain}")
            ->view('emails.gdpr.customer-data-export', [
                'shopDomain' => $this->shopDomain,
                'customerEmail' => $this->customerEmail,
                'recordCount' => $this->countRecords($this->exportData),
            ])
            ->attach(Attachment::fromData(
                fn () => json_encode($this->exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            )->as('customer-data-'.preg_replace('/[^a-z0-9]/i', '-', $this->customerEmail).'.json')
                ->withMime('application/json'));
    }

    private function countRecords(array $export): int
    {
        $count = 0;
        foreach ($export as $section) {
            if (is_array($section)) {
                $count += count($section);
            }
        }

        return $count;
    }
}
