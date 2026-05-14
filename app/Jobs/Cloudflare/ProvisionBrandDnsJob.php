<?php

namespace App\Jobs\Cloudflare;

use App\Jobs\Concerns\HasCloudflareRetryPolicy;
use App\Models\Core\Professional\Professional;
use App\Services\Cloudflare\CloudflareDnsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Provisions the Cloudflare CNAME for a brand's subdomain → shops.myshopify.com (DNS-only).
// Idempotent — safe to dispatch multiple times. No-op for non-brand professionals or
// professionals without a site row.
class ProvisionBrandDnsJob implements ShouldQueue
{
    use Dispatchable, HasCloudflareRetryPolicy, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function __construct(public readonly string $professionalId)
    {
        $this->onQueue('integrations');
    }

    public function handle(CloudflareDnsService $dns): void
    {
        $pro = Professional::query()->with('site')->find($this->professionalId);

        if (! $pro || ! $pro->isBrand()) {
            return;
        }

        $subdomain = $pro->site?->subdomain;
        if (! $subdomain) {
            Log::info('ProvisionBrandDnsJob: brand has no site row yet, skipping', [
                'professional_id' => $this->professionalId,
            ]);

            return;
        }

        // upsertCname is idempotent — safe to call repeatedly.
        // Oxygen requires DNS-only (proxied=false).
        $dns->upsertCname($subdomain, 'shops.myshopify.com', false);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('cloudflare.provision_brand_dns.failed', [
            'professional_id' => $this->professionalId,
            'error' => $e->getMessage(),
        ]);
    }
}
