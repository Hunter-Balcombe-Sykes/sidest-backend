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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Syncs one professional's subdomain routing entries in Cloudflare KV.
// Brands get {"type":"brand"} — Edge Worker passes through to Hydrogen.
// Affiliates get {"type":"affiliate","redirect":"https://brand.partna.au/handle"}.
// Every historical handle alias (professional_handle_aliases) gets the same
// entry as the current handle, so old shared <old>.partna.au URLs keep
// resolving after a rename instead of 404ing at the edge.
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

        // Current handle + every historical alias should resolve to the same
        // routing target. Lowercased to match how Cloudflare keys are looked
        // up (subdomain comparison is case-insensitive at the edge).
        $handles = collect([$pro->handle])
            ->concat($this->aliasHandles($pro->id))
            ->map(fn (string $h): string => strtolower(trim($h)))
            ->filter()
            ->unique()
            ->all();

        if ($pro->isBrand()) {
            foreach ($handles as $handle) {
                $kv->put($handle, ['type' => 'brand']);
            }

            return;
        }

        // Affiliate: use their primary brand link's precomputed site_url (brand.partna.au/affiliate).
        $siteUrl = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $pro->id)
            ->whereNotNull('site_url')
            ->orderBy('slot')
            ->value('site_url');

        if (! $siteUrl) {
            // No brand connection — retire every entry so Worker 404s on the
            // subdomain rather than redirecting somewhere stale.
            foreach ($handles as $handle) {
                try {
                    $kv->delete($handle);
                } catch (\Throwable $e) {
                    Log::warning('SyncSubdomainToKvJob: delete failed for unconnected affiliate', [
                        'professional_id' => $pro->id,
                        'handle' => $handle,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return;
        }

        foreach ($handles as $handle) {
            $kv->put($handle, ['type' => 'affiliate', 'redirect' => $siteUrl]);
        }
    }

    /** @return array<int, string> */
    private function aliasHandles(string $professionalId): array
    {
        return DB::table('site.professional_handle_aliases')
            ->where('professional_id', $professionalId)
            ->pluck('handle')
            ->all();
    }
}
