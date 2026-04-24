<?php

namespace App\Jobs\Shopify\Gdpr;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// Stub — full implementation in Day 2. Narrows scope to Shopify-derived data
// only (tokens, affiliate selections, synced customers); professional account survives.
class RedactShopJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public readonly string $gdprRequestId) {}

    public function handle(): void
    {
        // Implemented in Day 2.
    }
}
