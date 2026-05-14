<?php

namespace App\Jobs\Cache;

use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

// Deletes one affiliate public-payload cache key. Dispatched per-affiliate with a
// random 0–30s delay so a brand edit doesn't cold-miss all affiliate caches at once.
class InvalidateConnectedAffiliateCachesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    public int $timeout = 10;

    public function __construct(
        public string $subdomain
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $key = CacheKeyGenerator::publicSitePayload($this->subdomain);
        Cache::deleteMultiple([$key, $key.':stale']);
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
