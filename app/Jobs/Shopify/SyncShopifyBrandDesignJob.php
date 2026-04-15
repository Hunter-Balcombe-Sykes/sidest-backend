<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Shopify\BrandDesignImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// Unified brand-design importer. Replaces the old pair of
// SyncShopifyThemeTokensJob + SyncShopifyBrandLogoJob.
//
// What it does, per invocation:
//   1. Pulls the brand-design shape from Shopify via BrandDesignImporter
//      (Brand API for colours/logos/slogan; active theme's settings_data.json
//      for radius/border/spacing enums).
//   2. Downloads logo files from the Shopify CDN into our own media disk so
//      the URLs we persist are stable when Shopify's CDN tokens rotate.
//   3. Merges the new shape into site.settings.design using overwrite-if-present,
//      leave-if-absent semantics — a missing Shopify value never nulls a
//      user's existing edit.
//   4. Writes a `sidest.brand_design` shop metafield so other Sidest surfaces
//      (and debugging tools) can read the same shape from Shopify directly.
//   5. Busts the brand-design cache Hydrogen reads from.
class SyncShopifyBrandDesignJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    private const METAFIELDS_SET_MUTATION = <<<'GRAPHQL'
    mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) {
        metafields { id key }
        userErrors { field message code }
      }
    }
    GRAPHQL;

    public function __construct(
        public string $integrationId,
    ) {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return $this->integrationId;
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(BrandDesignImporter $importer): void
    {
        $integration = ProfessionalIntegration::query()
            ->where('id', $this->integrationId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return;
        }

        $site = Site::where('professional_id', $integration->professional_id)->first();

        if (! $site) {
            Log::warning('Brand design sync skipped — no site for professional.', [
                'integration_id' => $this->integrationId,
                'professional_id' => (string) $integration->professional_id,
            ]);

            return;
        }

        try {
            $imported = $importer->import($integration);
        } catch (\Throwable $e) {
            Log::warning('Brand design import failed.', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Download logos to our own storage so we don't depend on Shopify CDN
        // tokens. Failures are non-fatal — we fall back to the source URL.
        $logoFullUrl = $this->mirrorLogo(
            (string) $integration->professional_id,
            'full',
            is_string($imported['logo']['full_url'] ?? null) ? $imported['logo']['full_url'] : null,
        );
        $logoSquareUrl = $this->mirrorLogo(
            (string) $integration->professional_id,
            'square',
            is_string($imported['logo']['square_url'] ?? null) ? $imported['logo']['square_url'] : null,
        );

        // Merge into site.settings.design with leave-if-absent semantics.
        $settings = is_array($site->settings) ? $site->settings : [];
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];
        $existingColors = is_array($design['colors'] ?? null) ? $design['colors'] : [];
        $existingLogo = is_array($design['logo'] ?? null) ? $design['logo'] : [];

        $design['colors'] = [
            'background' => $imported['colors']['background'] ?? ($existingColors['background'] ?? null),
            'text' => $imported['colors']['text'] ?? ($existingColors['text'] ?? null),
            'accent' => $imported['colors']['accent'] ?? ($existingColors['accent'] ?? null),
            'border' => $imported['colors']['border'] ?? ($existingColors['border'] ?? null),
        ];
        $design['corner_radius'] = $imported['corner_radius'] ?? ($design['corner_radius'] ?? null);
        $design['border_thickness'] = $imported['border_thickness'] ?? ($design['border_thickness'] ?? null);
        $design['section_spacing'] = $imported['section_spacing'] ?? ($design['section_spacing'] ?? null);
        $design['logo'] = [
            'full_url' => $logoFullUrl ?? ($existingLogo['full_url'] ?? null),
            'square_url' => $logoSquareUrl ?? ($existingLogo['square_url'] ?? null),
        ];
        $design['slogan'] = $imported['slogan'] ?? ($design['slogan'] ?? null);

        $settings['design'] = $design;
        $site->settings = $settings;
        $site->save();

        // Mirror the shape to a shop metafield. Best-effort — a failure here
        // doesn't invalidate the DB write.
        if (! empty($imported['shop_gid'])) {
            try {
                $this->writeBrandDesignMetafield($integration, (string) $imported['shop_gid'], [
                    'colors' => $design['colors'],
                    'corner_radius' => $design['corner_radius'],
                    'border_thickness' => $design['border_thickness'],
                    'section_spacing' => $design['section_spacing'],
                    'slogan' => $design['slogan'],
                    'synced_at' => now()->toIso8601String(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to write sidest.brand_design metafield.', [
                    'integration_id' => $this->integrationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Cache::forget(CacheKeyGenerator::brandDesignConfig((string) $integration->professional_id));

        Log::info('Brand design synced.', [
            'integration_id' => $this->integrationId,
            'professional_id' => (string) $integration->professional_id,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Shopify brand design sync permanently failed.', [
            'integration_id' => $this->integrationId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Download a logo from the given Shopify CDN URL and mirror it onto our
     * media disk. Returns the public URL of the mirrored copy, or null if the
     * source URL was missing / the mirror failed (caller is expected to fall
     * back to the existing stored value in that case).
     */
    private function mirrorLogo(string $professionalId, string $variant, ?string $sourceUrl): ?string
    {
        if (! is_string($sourceUrl) || $sourceUrl === '' || ! str_starts_with($sourceUrl, 'https://')) {
            return null;
        }

        try {
            $response = Http::timeout(20)
                ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]])
                ->get($sourceUrl);

            if (! $response->ok()) {
                return null;
            }

            $bytes = $response->body();
            if ($bytes === '') {
                return null;
            }

            // Content hash in the filename means two consecutive syncs of the
            // same logo overwrite the same file — no orphaned junk piles up.
            $ext = $this->extensionFromContentType((string) $response->header('Content-Type')) ?? 'png';
            $hash = substr(hash('sha256', $bytes), 0, 16);
            $path = "brand-design/{$professionalId}/logo_{$variant}_{$hash}.{$ext}";

            $disk = Storage::disk($this->mediaDiskName());
            $disk->put($path, $bytes, 'public');

            $url = $disk->url($path);

            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable $e) {
            Log::warning('Failed to mirror brand logo from Shopify CDN.', [
                'integration_id' => $this->integrationId,
                'variant' => $variant,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function extensionFromContentType(string $contentType): ?string
    {
        $type = strtolower(trim(explode(';', $contentType)[0] ?? ''));

        return match ($type) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/gif' => 'gif',
            default => null,
        };
    }

    private function mediaDiskName(): string
    {
        // Prefer an explicit media disk if configured; otherwise fall back to
        // the app's default filesystem. Mirrors the resolution ImageVariantService does.
        $configured = (string) config('sidest.media_disk', 'media');
        $default = (string) config('filesystems.default', 'local');

        if ($configured !== 'media') {
            return $configured;
        }

        $defaultConfig = config("filesystems.disks.{$default}");
        if (is_array($defaultConfig) && ($defaultConfig['driver'] ?? null) === 's3') {
            return $default;
        }

        return $configured;
    }

    private function writeBrandDesignMetafield(ProfessionalIntegration $integration, string $shopGid, array $value): void
    {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);
        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));

        if ($shopDomain === '' || $accessToken === '') {
            return;
        }

        $endpoint = "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json";

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode brand design for metafield.');
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->post($endpoint, [
                'query' => self::METAFIELDS_SET_MUTATION,
                'variables' => [
                    'metafields' => [[
                        'namespace' => 'sidest',
                        'key' => 'brand_design',
                        'ownerId' => $shopGid,
                        'type' => 'json',
                        'value' => $encoded,
                    ]],
                ],
            ]);

        if (! $response->ok()) {
            throw new \RuntimeException("Shopify metafieldsSet failed (HTTP {$response->status()}).");
        }

        $userErrors = $response->json('data.metafieldsSet.userErrors', []);
        if (is_array($userErrors) && $userErrors !== []) {
            $message = (string) Arr::get($userErrors, '0.message', 'Shopify metafieldsSet returned errors.');
            throw new \RuntimeException($message);
        }
    }
}
