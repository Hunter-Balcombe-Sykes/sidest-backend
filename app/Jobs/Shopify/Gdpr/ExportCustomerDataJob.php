<?php

namespace App\Jobs\Shopify\Gdpr;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// Stub — full implementation in Day 3. Packages customer PII as JSON and
// emails it to the merchant (merchant-forward pattern, Shopify-recommended).
class ExportCustomerDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $gdprRequestId) {}

    public function handle(): void
    {
        // Implemented in Day 3.
    }
}
