<?php

namespace App\Mail;

use App\Models\Core\Site\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// V2: Notifies the affiliate's configured inbox of a new enquiry submitted via the contact section block.
class SiteEnquiryNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Enquiry $enquiry,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New enquiry from {$this->enquiry->name} — {$this->enquiry->subject}",
        );
    }

    public function content(): Content
    {
        $dashboardUrl = rtrim((string) config('app.dashboard_url', config('app.url')), '/').'/enquiries';

        return new Content(
            view: 'emails.enquiry-notification',
            with: [
                'enquiry' => $this->enquiry,
                'dashboardUrl' => $dashboardUrl,
            ],
        );
    }
}
