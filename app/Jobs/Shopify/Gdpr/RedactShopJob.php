<?php

namespace App\Jobs\Shopify\Gdpr;

use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Customer;
use App\Services\Shopify\ShopifyShopResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// V2: Handles Shopify `shop/redact` webhook. NARROW scope — removes the Shopify
// integration and Shopify-derived data, but leaves the professional account
// intact (they may still be using Fresha or Square). Timeout is generous because
// a mature shop can have thousands of Shopify-sourced customers to anonymise.
class RedactShopJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public string $gdprRequestId)
    {
        // redis_gdpr connection has retry_after=660 so Redis won't re-queue
        // this job while the 600s chunkById sweep is still running.
        $this->onConnection('redis_gdpr')->onQueue(config('sidest.gdpr.queue'));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $resolver = app(ShopifyShopResolver::class);
        $gdpr = GdprRequest::find($this->gdprRequestId);

        if (! $gdpr) {
            Log::warning('RedactShopJob: gdpr_requests row not found', ['id' => $this->gdprRequestId]);

            return;
        }

        // Idempotent: re-run on a completed/skipped request is a no-op. Shopify
        // retries fire at the transport layer; the payload_hash unique index
        // prevents duplicate rows, but if this job itself is re-queued (Horizon
        // restart, etc.) we must not re-process.
        if (in_array($gdpr->status, [GdprRequest::STATUS_COMPLETED, GdprRequest::STATUS_SKIPPED], true)) {
            return;
        }

        $gdpr->update(['status' => GdprRequest::STATUS_PROCESSING]);

        try {
            $integration = $resolver->resolveIntegration($gdpr->shop_domain);

            if (! $integration) {
                $gdpr->markSkipped('no integration for shop_domain (already redacted or never installed)');

                return;
            }

            $professionalId = $integration->professional_id;
            $gdpr->update(['professional_id' => $professionalId]);

            // 1. Revoke API access FIRST. If anything below crashes, the
            //    integration is already cut off from Shopify.
            $integration->update([
                'access_token' => null,
                'refresh_token' => null,
            ]);

            // 2. Delete affiliate product selections scoped to this brand.
            //    Shopify product GIDs become stale the moment the integration is gone.
            $deletedSelections = AffiliateProductSelection::query()
                ->where('brand_professional_id', $professionalId)
                ->delete();

            // 3. Anonymise Shopify-sourced customers. Other sources (fresha,
            //    square, manual) are preserved — the professional may still be
            //    using those integrations.
            $anonymisedCount = $this->anonymiseShopifyCustomers($professionalId);

            // 4. Scrub PII on commerce.orders for this brand. shopify_data holds the
            //    raw Shopify payload (customer object + addresses); order_events.metadata
            //    holds refund/adjustment notes. Full-nuke both JSONB columns and null
            //    customer_id. Cents/status columns are kept for analytics.
            $orderIds = DB::connection('pgsql')
                ->table('commerce.orders')
                ->where('brand_professional_id', $professionalId)
                ->pluck('id')
                ->all();

            $scrubbedOrders = 0;
            if (! empty($orderIds)) {
                $scrubbedOrders = DB::connection('pgsql')
                    ->table('commerce.orders')
                    ->whereIn('id', $orderIds)
                    ->update([
                        'shopify_data' => '{}',
                        'customer_id' => null,
                        'updated_at' => now(),
                    ]);

                DB::connection('pgsql')
                    ->table('commerce.order_events')
                    ->whereIn('order_id', $orderIds)
                    ->update(['metadata' => '{}']);
            }

            // 5. Delete the integration row LAST. This removes the
            //    shopify_shop_domain key, so subsequent retries fall into the
            //    "no integration" skip branch above.
            $integration->delete();

            $gdpr->markCompleted();

            Log::info('RedactShopJob completed (narrow scope).', [
                'gdpr_request_id' => $gdpr->id,
                'professional_id' => $professionalId,
                'shop_domain' => $gdpr->shop_domain,
                'deleted_selections' => $deletedSelections,
                'anonymised_customers' => $anonymisedCount,
                'scrubbed_orders' => $scrubbedOrders,
            ]);
        } catch (\Throwable $e) {
            $gdpr->markFailed($e->getMessage());
            Log::error('RedactShopJob failed', [
                'gdpr_request_id' => $gdpr->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Anonymise every customer row tied to this professional and sourced from
     * Shopify. Email becomes redacted-{uuid}@placeholder, name becomes a
     * literal placeholder, phone and external_id are nulled.
     *
     * Uses chunkById(500) to keep memory bounded at scale — a mature shop
     * can have tens of thousands of customers. chunkById is safe here: we
     * update `redacted_at` on each row, so already-processed rows drop out
     * of the `whereNull('redacted_at')` filter, and the id cursor only
     * advances forward.
     */
    private function anonymiseShopifyCustomers(string $professionalId): int
    {
        $placeholderDomain = config('sidest.gdpr.redact_placeholder_domain', 'gdpr.sidest.io');
        $count = 0;

        Customer::query()
            ->withTrashed()
            ->where('professional_id', $professionalId)
            ->where('source', 'shopify')
            ->whereNull('redacted_at')
            ->chunkById(500, function ($customers) use ($placeholderDomain, &$count) {
                foreach ($customers as $customer) {
                    $customer->update([
                        'email' => 'redacted-'.Str::uuid()->toString().'@'.$placeholderDomain,
                        'phone' => null,
                        'full_name' => 'Redacted Customer',
                        'external_id' => null,
                        'notes' => null,
                        'marketing_opt_in_cached' => null,
                        'redacted_at' => now(),
                    ]);
                    $count++;
                }
            });

        return $count;
    }
}
