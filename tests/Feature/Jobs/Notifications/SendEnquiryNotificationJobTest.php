<?php

use App\Jobs\Notifications\SendEnquiryNotificationJob;
use Illuminate\Support\Facades\Log;

it('has correct reliability properties', function () {
    $job = new SendEnquiryNotificationJob('enquiry-uuid', 'notify@example.com');

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([30, 90, 180]);
    expect($job->timeout)->toBe(30);
});

it('failed() reports the exception and logs enquiry context without leaking the notification email', function () {
    Log::spy();

    $job = new SendEnquiryNotificationJob('enquiry-uuid-123', 'notify@example.com');
    $e = new RuntimeException('mail transport failed');

    $job->failed($e);

    Log::shouldHaveReceived('error')
        ->atLeast()->once()
        ->withArgs(function (string $message, array $context) {
            // notification_email is intentionally omitted — log retention
            // exceeds GDPR/Privacy Act scrubbing scope, and enquiry_id is
            // sufficient to recover the email during incident response.
            return $message === 'SendEnquiryNotificationJob failed permanently'
                && $context['enquiry_id'] === 'enquiry-uuid-123'
                && ! array_key_exists('notification_email', $context)
                && str_contains($context['error'], 'mail transport failed');
        });
});
