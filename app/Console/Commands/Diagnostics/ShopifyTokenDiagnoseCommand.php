<?php

namespace App\Console\Commands\Diagnostics;

use App\Jobs\Shopify\CreateShopifyCollectionsJob;
use App\Jobs\Shopify\CreateShopifyMetafieldsJob;
use App\Jobs\Shopify\CreateShopifySalesChannelJob;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Jobs\Shopify\SyncShopifyBrandDesignJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

// Diagnostic-only: decrypts the stored Shopify Admin token for an integration
// and pings Shopify directly to confirm whether the token is currently valid.
// Optionally re-dispatches the failed setup-pipeline jobs so we can watch
// provider_metadata flip from `failed` to `registered` live.
class ShopifyTokenDiagnoseCommand extends Command
{
    protected $signature = 'shopify:diagnose
        {integration_id : ProfessionalIntegration UUID}
        {--retry-failed : Re-dispatch failed setup-pipeline steps after the ping}';

    protected $description = 'Ping Shopify with the stored Admin token to confirm validity; optionally retry failed setup steps.';

    public function handle(): int
    {
        $id = (string) $this->argument('integration_id');
        $integration = ProfessionalIntegration::find($id);

        if (! $integration) {
            $this->error("No integration found for {$id}");

            return self::FAILURE;
        }

        $shop = (string) ($integration->provider_metadata['shop_domain'] ?? '');
        $token = (string) ($integration->access_token ?? '');

        if ($shop === '' || $token === '') {
            $this->error("Missing shop_domain or access_token (shop='{$shop}', token_len=".strlen($token).')');

            return self::FAILURE;
        }

        // Token shape check — Shopify offline tokens are `shpat_` + 32 hex chars.
        // If decrypt produced something else, that's a storage/encryption issue,
        // not a Shopify-side rejection.
        $this->line("shop:           {$shop}");
        $this->line('token len:      '.strlen($token));
        $this->line('token prefix:   '.substr($token, 0, 8).'...');
        $this->line('looks like shpat_? '.(str_starts_with($token, 'shpat_') ? 'yes' : 'NO'));
        $this->newLine();

        // 1) REST ping — /shop.json is the canonical "is this token valid" probe.
        $this->info('--- Admin REST: GET /shop.json ---');
        $r = Http::withHeaders(['X-Shopify-Access-Token' => $token])
            ->acceptJson()
            ->timeout(15)
            ->get("https://{$shop}/admin/api/2025-01/shop.json");
        $this->line('status:  '.$r->status());
        $this->line('body:    '.substr($r->body(), 0, 500));
        $this->newLine();

        // 2) GraphQL ping — separate endpoint, separate scope check.
        $this->info('--- Admin GraphQL: { shop { id name } } ---');
        $g = Http::withHeaders(['X-Shopify-Access-Token' => $token])
            ->acceptJson()
            ->timeout(15)
            ->post("https://{$shop}/admin/api/2025-01/graphql.json", [
                'query' => '{ shop { id name myshopifyDomain } }',
            ]);
        $this->line('status:  '.$g->status());
        $this->line('body:    '.substr($g->body(), 0, 500));
        $this->newLine();

        $tokenWorks = $r->status() === 200 && $g->status() === 200;
        $this->{$tokenWorks ? 'info' : 'error'}('Verdict: token is '.($tokenWorks ? 'VALID' : 'REJECTED').' by Shopify right now.');

        if (! $this->option('retry-failed')) {
            return self::SUCCESS;
        }

        if (! $tokenWorks) {
            $this->warn('Skipping retry: token is rejected, retrying would just re-fail.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('--- Re-dispatching failed setup steps ---');
        // Post-DATA-2: webhook state is the webhook_registration_state column;
        // the other four steps still live in provider_metadata.
        $jsonbStepMap = [
            'metafield_definitions_state' => CreateShopifyMetafieldsJob::class,
            'collections_state' => CreateShopifyCollectionsJob::class,
            'sales_channel_state' => CreateShopifySalesChannelJob::class,
            'brand_design_state' => SyncShopifyBrandDesignJob::class,
        ];

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

        $webhookState = $integration->webhook_registration_state;
        if (in_array($webhookState, ['registered', 'synced'], true)) {
            $this->line("skip webhook_registration_state ({$webhookState})");
        } else {
            $integration->update(['webhook_registration_state' => 'queued']);
            RegisterShopifyWebhooksJob::dispatch((string) $integration->id);
            $this->line('dispatched '.RegisterShopifyWebhooksJob::class." (was {$webhookState})");
        }

        foreach ($jsonbStepMap as $stateKey => $jobClass) {
            $current = $metadata[$stateKey] ?? null;
            if (in_array($current, ['registered', 'synced'], true)) {
                $this->line("skip {$stateKey} ({$current})");

                continue;
            }
            $integration->mergeProviderMetadata([$stateKey => 'queued']);
            $jobClass::dispatch((string) $integration->id);
            $this->line("dispatched {$jobClass} (was {$current})");
        }

        $this->newLine();
        $this->info('Jobs queued. Re-run this command in 30-60s without --retry-failed to confirm tokens still work, then check provider_metadata for state changes.');

        return self::SUCCESS;
    }
}
