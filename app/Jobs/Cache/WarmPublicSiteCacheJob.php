<?php

namespace App\Jobs\Cache;

use App\Services\Cache\SiteCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WarmPublicSiteCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $queue = 'cache';

    public function __construct(
        public string $subdomain
    ) {}

    public function handle(SiteCacheService $siteCache): void
    {
        $siteCache->warmSiteCache(strtolower($this->subdomain));
    }
}
