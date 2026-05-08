<?php

namespace App\Jobs\Cloudflare;

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Services\Cloudflare\CloudflareKvService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Syncs one professional's subdomain routing entry in Cloudflare KV.
// Brands get {"type":"brand"} — Edge Worker passes through to Hydrogen.
// Affiliates get {"type":"affiliate","redirect":"https://brand.partna.au/handle"}.
// Dispatched by observers on: handle change, brand_partner_links change, brand URL change.
class SyncSubdomainToKvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $professionalId) {}

    public function handle(CloudflareKvService $kv): void
    {
        $pro = Professional::query()->find($this->professionalId);

        if (! $pro || ! $pro->handle) {
            return;
        }

        if ($pro->isBrand()) {
            $kv->put($pro->handle, ['type' => 'brand']);

            return;
        }

        // Affiliate: use their primary brand link's precomputed site_url (brand.partna.au/affiliate).
        $siteUrl = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $pro->id)
            ->whereNotNull('site_url')
            ->orderBy('slot')
            ->value('site_url');

        if (! $siteUrl) {
            // No brand connection — remove entry so Worker falls back gracefully
            try {
                $kv->delete($pro->handle);
            } catch (\Throwable $e) {
                Log::warning('SyncSubdomainToKvJob: delete failed for unconnected affiliate', [
                    'professional_id' => $pro->id,
                    'handle' => $pro->handle,
                    'message' => $e->getMessage(),
                ]);
            }

            return;
        }

        $kv->put($pro->handle, ['type' => 'affiliate', 'redirect' => $siteUrl]);
    }
}
