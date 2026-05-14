<?php

namespace App\Jobs\Concerns;

trait HasCloudflareRetryPolicy
{
    public int $tries = 3;

    public array $backoff = [10, 30, 60];
}
