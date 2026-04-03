<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// V2: Fetches brand logo from Shopify via GraphQL and writes URL to Site settings. Always overwrites existing logo.
class SyncShopifyBrandLogoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

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

    public function handle(): void
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

        if ($shopDomain === '' || $accessToken === '') {
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

            return;
        }

        if ($logoUrl === null) {
            return;
        }

        $site = Site::where('professional_id', $integration->professional_id)->first();

        if (! $site) {
            return;
        }

        $settings = is_array($site->settings) ? $site->settings : [];
        Arr::set($settings, 'design.media.brand_logo_url', $logoUrl);
        $site->settings = $settings;
        $site->save();

        Log::info('Shopify brand logo synced.', [
            'integration_id' => $this->integrationId,
            'shop_domain' => $shopDomain,
            'logo_url' => $logoUrl,
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
}
