<?php

use App\Jobs\Cloudflare\RetireBrandDnsJob;
use App\Services\Cloudflare\CloudflareDnsService;
use Tests\TestCase;

use function Pest\Laravel\mock;

uses(TestCase::class)->in(__FILE__);

it('has the correct retry policy and queue configuration', function () {
    $job = new RetireBrandDnsJob('testsubdomain');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 30, 60])
        ->and($job->timeout)->toBe(30)
        ->and($job->queue)->toBe('integrations');
});

it('finds and deletes the CNAME for the retired subdomain', function () {
    config(['partna.public_domain' => 'partna.au']);

    $dns = mock(CloudflareDnsService::class);
    $dns->shouldReceive('findRecord')
        ->once()
        ->with('CNAME', 'oldname.partna.au')
        ->andReturn(['id' => 'rec-123', 'name' => 'oldname.partna.au']);
    $dns->shouldReceive('deleteRecord')
        ->once()
        ->with('rec-123');

    (new RetireBrandDnsJob('oldname'))->handle($dns);
});

it('no-ops when the record does not exist', function () {
    config(['partna.public_domain' => 'partna.au']);

    $dns = mock(CloudflareDnsService::class);
    $dns->shouldReceive('findRecord')
        ->once()
        ->with('CNAME', 'gone.partna.au')
        ->andReturn(null);
    $dns->shouldNotReceive('deleteRecord');

    (new RetireBrandDnsJob('gone'))->handle($dns);
});

it('no-ops when subdomain is empty', function () {
    $dns = mock(CloudflareDnsService::class);
    $dns->shouldNotReceive('findRecord');
    $dns->shouldNotReceive('deleteRecord');

    (new RetireBrandDnsJob(''))->handle($dns);
});
