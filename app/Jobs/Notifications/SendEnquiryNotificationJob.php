<?php

namespace App\Jobs\Notifications;

use App\Mail\SiteEnquiryNotification;
use App\Models\Core\Site\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// V2: Sends the contact-form notification email to the affiliate's configured inbox after an enquiry is saved.
class SendEnquiryNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 90, 180];

    public int $timeout = 30;

    public function __construct(
        public readonly string $enquiryId,
        public readonly string $notificationEmail,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $enquiry = Enquiry::query()->find($this->enquiryId);

        if (! $enquiry) {
            Log::warning('SendEnquiryNotificationJob: enquiry not found', [
                'enquiry_id' => $this->enquiryId,
            ]);

            return;
        }

        Mail::to($this->notificationEmail)->send(new SiteEnquiryNotification($enquiry));
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        Log::error('SendEnquiryNotificationJob failed permanently', [
            'enquiry_id' => $this->enquiryId,
            'notification_email' => $this->notificationEmail,
            'error' => $e->getMessage(),
        ]);
    }
}
