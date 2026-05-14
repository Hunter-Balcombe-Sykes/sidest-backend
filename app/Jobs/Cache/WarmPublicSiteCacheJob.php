<?php

namespace App\Jobs\Cache;

use App\Services\Cache\SiteCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// V2: Pre-warms public site cache after publish events. Prevents cold-cache latency for first visitor.
class WarmPublicSiteCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    public int $timeout = 10;

    public function __construct(
        public string $subdomain
    ) {
        // Use the 'default' queue so standard workers pick this up automatically.
        // Previously dispatched to 'cache', a named queue that may not be consumed
        // in all worker deployments, which would silently prevent cache warming.
        $this->onQueue('default');
    }

    public function handle(SiteCacheService $siteCache): void
    {
        $siteCache->warmSiteCache(strtolower($this->subdomain));
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
