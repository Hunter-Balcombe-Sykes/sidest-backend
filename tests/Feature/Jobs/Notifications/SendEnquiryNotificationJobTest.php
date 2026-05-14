<?php

use App\Jobs\Notifications\SendEnquiryNotificationJob;
use Illuminate\Support\Facades\Log;

it('has correct reliability properties', function () {
    $job = new SendEnquiryNotificationJob('enquiry-uuid', 'notify@example.com');

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([30, 90, 180]);
    expect($job->timeout)->toBe(30);
});

it('failed() reports the exception and logs enquiry context', function () {
    Log::spy();

    $job = new SendEnquiryNotificationJob('enquiry-uuid-123', 'notify@example.com');
    $e = new RuntimeException('mail transport failed');

    $job->failed($e);

    Log::shouldHaveReceived('error')
        ->atLeast()->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'SendEnquiryNotificationJob failed permanently'
                && $context['enquiry_id'] === 'enquiry-uuid-123'
                && $context['notification_email'] === 'notify@example.com'
                && str_contains($context['error'], 'mail transport failed');
        });
});
