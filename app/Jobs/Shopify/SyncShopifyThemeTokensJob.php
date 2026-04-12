<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Shopify\ThemeTokenParserService;
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

// V2: Fetches brand's Shopify storefront HTML, extracts design tokens via ThemeTokenParserService,
// writes to sidest.theme_tokens shop metafield (Shopify) + provider_metadata.theme_tokens (DB).
class SyncShopifyThemeTokensJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    private const MAX_HTML_BYTES = 5_242_880; // 5 MiB

    private const SHOP_DOMAIN_QUERY = <<<'GRAPHQL'
    {
      shop {
        id
        myshopifyDomain
        primaryDomain {
          url
        }
      }
    }
    GRAPHQL;

    private const METAFIELDS_SET_MUTATION = <<<'GRAPHQL'
    mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) {
        metafields {
          id
          key
        }
        userErrors {
          field
          message
          code
        }
      }
    }
    GRAPHQL;

    public function __construct(
        public string $integrationId
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

    public function handle(ThemeTokenParserService $parser): void
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
            Log::warning('Skipping theme token sync — invalid shop credentials.', [
                'integration_id' => $this->integrationId,
            ]);

            return;
        }

        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));

        try {
            $shopInfo = $this->fetchShopInfo($shopDomain, $accessToken, $apiVersion);
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch Shopify shop info for theme token sync.', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $shopGid = $shopInfo['gid'] ?? null;
        $storefrontUrl = $this->resolveStorefrontUrl($shopInfo['primary_domain_url'] ?? null, $shopDomain);

        if (! is_string($shopGid) || $shopGid === '' || ! is_string($storefrontUrl)) {
            Log::warning('Theme token sync could not resolve shop GID or storefront URL.', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
            ]);

            return;
        }

        try {
            $html = $this->fetchStorefrontHtml($storefrontUrl);
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch brand storefront HTML.', [
                'integration_id' => $this->integrationId,
                'storefront_url' => $storefrontUrl,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $tokens = $parser->extractTokens($html);

        if (empty($tokens)) {
            Log::info('Theme token sync produced no tokens.', [
                'integration_id' => $this->integrationId,
                'storefront_url' => $storefrontUrl,
            ]);
        }

        try {
            $this->writeThemeTokensMetafield($shopDomain, $accessToken, $apiVersion, $shopGid, $tokens);
        } catch (\Throwable $e) {
            Log::warning('Failed to write theme_tokens metafield to Shopify.', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $integration->mergeProviderMetadata([
            'theme_tokens' => $tokens,
            'theme_tokens_synced_at' => now()->toIso8601String(),
            'primary_domain_url' => $storefrontUrl,
        ]);

        Cache::forget(CacheKeyGenerator::brandDesignConfig((string) $integration->professional_id));

        Log::info('Shopify theme tokens synced.', [
            'integration_id' => $this->integrationId,
            'shop_domain' => $shopDomain,
            'token_count' => count($tokens),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Shopify theme token sync permanently failed.', [
            'integration_id' => $this->integrationId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * @return array{gid: ?string, primary_domain_url: ?string}
     */
    private function fetchShopInfo(string $shopDomain, string $accessToken, string $apiVersion): array
    {
        $endpoint = "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json";

        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->post($endpoint, ['query' => self::SHOP_DOMAIN_QUERY]);

        if (! $response->ok()) {
            throw new \RuntimeException("Shopify GraphQL request failed (HTTP {$response->status()}).");
        }

        $errors = $response->json('errors', []);
        if (is_array($errors) && $errors !== []) {
            $message = (string) Arr::get($errors, '0.message', 'Shopify GraphQL returned errors.');
            throw new \RuntimeException($message);
        }

        return [
            'gid' => $response->json('data.shop.id'),
            'primary_domain_url' => $response->json('data.shop.primaryDomain.url'),
        ];
    }

    /**
     * Prefer the primary (custom) domain URL, fall back to the .myshopify.com domain.
     * Enforces https + hostname sanity checks — no SSRF.
     */
    private function resolveStorefrontUrl(?string $primaryDomainUrl, string $shopDomain): ?string
    {
        $candidates = [];

        if (is_string($primaryDomainUrl) && $primaryDomainUrl !== '') {
            $candidates[] = $primaryDomainUrl;
        }

        $candidates[] = "https://{$shopDomain}";

        foreach ($candidates as $url) {
            if ($this->isSafeStorefrontUrl($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Validate that a URL is safe to fetch: https, valid host, not a private/internal IP.
     */
    private function isSafeStorefrontUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        // Reject any URL with credentials
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        // Hostnames must be DNS-safe. Reject IP literals directly to avoid private-range SSRF.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        // Block obvious internal-only hostnames
        $blocked = ['localhost', 'metadata.google.internal', 'metadata.aws.internal'];
        if (in_array($host, $blocked, true)) {
            return false;
        }

        // Require at least one dot (TLD) in the hostname
        if (! str_contains($host, '.')) {
            return false;
        }

        // Allow only [a-z0-9.-] in the hostname
        if (! preg_match('/^[a-z0-9.\-]+$/', $host)) {
            return false;
        }

        return true;
    }

    private function fetchStorefrontHtml(string $url): string
    {
        $response = Http::timeout(20)
            ->withUserAgent('SideSt-ThemeSync/1.0 (+https://sidest.co)')
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en',
            ])
            ->withOptions([
                'allow_redirects' => ['max' => 5, 'strict' => true, 'protocols' => ['https']],
            ])
            ->get($url);

        if (! $response->ok()) {
            throw new \RuntimeException("Storefront fetch failed (HTTP {$response->status()}).");
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_HTML_BYTES) {
            $body = substr($body, 0, self::MAX_HTML_BYTES);
        }

        return $body;
    }

    private function writeThemeTokensMetafield(string $shopDomain, string $accessToken, string $apiVersion, string $shopGid, array $tokens): void
    {
        $endpoint = "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json";

        $value = json_encode($tokens, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($value === false) {
            throw new \RuntimeException('Failed to encode theme tokens as JSON.');
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->post($endpoint, [
                'query' => self::METAFIELDS_SET_MUTATION,
                'variables' => [
                    'metafields' => [[
                        'namespace' => 'sidest',
                        'key' => 'theme_tokens',
                        'ownerId' => $shopGid,
                        'type' => 'json',
                        'value' => $value,
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
