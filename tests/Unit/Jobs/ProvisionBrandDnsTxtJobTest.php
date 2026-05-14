<?php

use App\Jobs\Cloudflare\ProvisionBrandDnsTxtJob;
use App\Services\Cloudflare\CloudflareDnsService;
use Tests\TestCase;

use function Pest\Laravel\mock;

uses(TestCase::class)->in(__FILE__);

it('upserts a Shopify domain verification TXT record', function () {
    $dns = mock(CloudflareDnsService::class);
    $dns->shouldReceive('upsertTxt')
        ->once()
        ->with('shopify_verification_evostudio', 'shopify-verification=token-abc-123');

    (new ProvisionBrandDnsTxtJob(
        professionalId: 'pro-123',
        recordName: 'shopify_verification_evostudio',
        txtValue: 'shopify-verification=token-abc-123',
    ))->handle($dns);
});

it('is placed on the integrations queue', function () {
    $job = new ProvisionBrandDnsTxtJob('pro-123', 'shopify_verification_evostudio', 'tok');

    expect($job->queue)->toBe('integrations');
});
