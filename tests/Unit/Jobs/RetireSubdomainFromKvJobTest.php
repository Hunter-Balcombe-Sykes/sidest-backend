<?php

use App\Jobs\Cloudflare\RetireSubdomainFromKvJob;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('has the correct retry policy and queue configuration', function () {
    $job = new RetireSubdomainFromKvJob('myhandle');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 30, 60])
        ->and($job->timeout)->toBe(30)
        ->and($job->queue)->toBe('integrations');
});
