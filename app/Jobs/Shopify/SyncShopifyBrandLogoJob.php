<?php

namespace App\Jobs\Shopify;

use App\Jobs\ProcessImageVariantsJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// V2: Fetches brand logo from Shopify via GraphQL, ingests it through the SiteMedia
// pipeline (R2 original + WebP variants), and writes the resulting variant URL into
// site.settings.design.media. Dedupes by content hash so repeat runs are cheap.
class SyncShopifyBrandLogoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Covers GraphQL + CDN download + inline variant processing for a single logo.
    public int $timeout = 120;

    public int $uniqueFor = 300;

    // Brand logos should never be huge — cap the download so a compromised or
    // misconfigured Shopify store can't shovel a multi-gig payload into memory.
    private const MAX_LOGO_BYTES = 5_242_880; // 5 MB

    // Hosts we accept for the Shopify CDN image URL. Anything outside this list
    // is refused as a defense-in-depth measure against SSRF / URL spoofing in
    // the GraphQL response (the URL itself is attacker-influenced if a store
    // owner is malicious).
    private const ALLOWED_LOGO_HOSTS = [
        'cdn.shopify.com',
    ];

    public function uniqueId(): string
    {
        return $this->integrationId;
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    private const SHOP_BRAND_LOGO_QUERY = <<<'GRAPHQL'
    {
      shop {
        brand {
          squareLogo {
            image {
              url
            }
          }
        }
      }
    }
    GRAPHQL;

    public function __construct(
        public string $integrationId
    ) {
        $this->onQueue('integrations');
    }

    public function handle(ImageVariantService $mediaService, SiteCacheService $siteCache): void
    {
        $integration = ProfessionalIntegration::query()
            ->where('id', $this->integrationId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return;
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);

        if ($shopDomain === '' || $accessToken === '' || ! preg_match('/^[a-z0-9\-]+\.myshopify\.com$/', $shopDomain)) {
            return;
        }

        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));

        try {
            $logoUrl = $this->fetchLogoUrl($shopDomain, $accessToken, $apiVersion);
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch Shopify brand logo.', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($logoUrl === null) {
            return;
        }

        if (! $this->isAllowedLogoHost($logoUrl)) {
            Log::warning('Rejected Shopify brand logo URL from non-allowlisted host.', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
                'logo_url_host' => parse_url($logoUrl, PHP_URL_HOST),
            ]);

            return;
        }

        $site = Site::where('professional_id', $integration->professional_id)->first();

        if (! $site) {
            return;
        }

        // Download once; every subsequent decision (dedupe, store, variants) keys
        // off the same content hash so repeat runs can bail out without re-work.
        try {
            $download = $mediaService->downloadRemoteImage(
                url: $logoUrl,
                maxBytes: self::MAX_LOGO_BYTES,
                timeoutSeconds: 20,
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to download Shopify brand logo bytes.', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $hash16 = substr($download['sha256'], 0, 16);

        $existing = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->where('alt_text', 'logo')
            ->whereNull('deleted_at')
            ->first();

        // Dedupe: if the prior logo is fully-processed and its content-hashed
        // filename matches the bytes we just downloaded, the logo is unchanged.
        // Skip the re-ingest to save a disk write + variant regen + cache bust.
        if (
            $existing
            && $existing->processing_state === SiteMedia::PROCESSING_STATE_READY
            && is_string($existing->path)
            && str_contains($existing->path, "original_{$hash16}.")
        ) {
            Log::info('Shopify brand logo unchanged; skipping re-ingest.', [
                'integration_id' => $this->integrationId,
                'site_id' => $site->id,
                'existing_path' => $existing->path,
            ]);

            return;
        }

        // Create a replacement SiteMedia row inside a transaction so the soft-delete
        // of any prior logo and the new insert land atomically against the design-pool
        // singleton index (site_media_design_logo_uq).
        $media = DB::transaction(function () use ($site, $download) {
            SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('alt_text', 'logo')
                ->whereNull('deleted_at')
                ->get()
                ->each(fn (SiteMedia $existing) => $existing->delete());

            return SiteMedia::create([
                'site_id' => $site->id,
                'pool' => SiteMedia::POOL_DESIGN,
                'path' => '',
                'alt_text' => 'logo',
                'sort_order' => 0,
                'is_active' => true,
                'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
                'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
                'original_mime' => $download['mime'],
                'original_size_bytes' => $download['size'],
            ]);
        });

        $basePath = "images/{$integration->professional_id}/{$media->id}";
        $originalPath = $mediaService->storeOriginalBytes(
            bytes: $download['bytes'],
            basePath: $basePath,
            ext: $download['ext'],
            sha256: $download['sha256'],
        );

        $media->update(['path' => $originalPath]);

        // Inline variant processing: we run inside a queued job already, and the
        // follow-up steps (writing the variant URL to site.settings) need the
        // variants to exist. Dispatching sync keeps the pipeline single-hop.
        ProcessImageVariantsJob::dispatchSync(
            originalPath: $originalPath,
            imageId: (string) $media->id,
            basePath: $basePath,
        );

        $media->refresh();
        $media->load('mediaVariants');

        if ($media->processing_state !== SiteMedia::PROCESSING_STATE_READY) {
            Log::warning('Shopify brand logo variant processing did not reach ready state.', [
                'integration_id' => $this->integrationId,
                'media_id' => $media->id,
                'processing_state' => $media->processing_state,
                'processing_error' => $media->processing_error,
            ]);

            return;
        }

        $variantUrls = $media->variantUrls();
        $variantUrl = $variantUrls['optimized'] ?? $variantUrls['maximized'] ?? null;

        if (! is_string($variantUrl) || $variantUrl === '') {
            Log::warning('Shopify brand logo has no usable variant URL after processing.', [
                'integration_id' => $this->integrationId,
                'media_id' => $media->id,
                'variant_keys' => array_keys($variantUrls),
            ]);

            return;
        }

        $settings = is_array($site->settings) ? $site->settings : [];
        Arr::set($settings, 'design.media.brand_logo_url', $variantUrl);
        Arr::set($settings, 'design.media.brand_logo_path', $originalPath);
        Arr::set($settings, 'design.media.brand_logo_name', "shopify-brand-logo.{$download['ext']}");
        $site->settings = $settings;
        $site->save();

        // Hydrogen reads logo_url via HydrogenBrandDesignController which caches
        // under this key for 5 min; bust it so storefronts pick up the new logo
        // on their next render instead of waiting out the TTL.
        Cache::forget(CacheKeyGenerator::brandDesignConfig((string) $integration->professional_id));
        $siteCache->invalidateSite($site);

        Log::info('Shopify brand logo synced via SiteMedia pipeline.', [
            'integration_id' => $this->integrationId,
            'shop_domain' => $shopDomain,
            'media_id' => $media->id,
            'variant_url' => $variantUrl,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Shopify brand logo sync permanently failed', [
            'integration_id' => $this->integrationId,
            'error' => $e->getMessage(),
        ]);
    }

    private function fetchLogoUrl(string $shopDomain, string $accessToken, string $apiVersion): ?string
    {
        $endpoint = "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json";

        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])
            ->post($endpoint, [
                'query' => self::SHOP_BRAND_LOGO_QUERY,
            ]);

        if (! $response->ok()) {
            throw new \RuntimeException("Shopify GraphQL request failed (HTTP {$response->status()}).");
        }

        $errors = $response->json('errors', []);
        if (is_array($errors) && $errors !== []) {
            $message = (string) Arr::get($errors, '0.message', 'Shopify GraphQL returned errors.');
            throw new \RuntimeException($message);
        }

        $url = $response->json('data.shop.brand.squareLogo.image.url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function isAllowedLogoHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), self::ALLOWED_LOGO_HOSTS, true);
    }
}
