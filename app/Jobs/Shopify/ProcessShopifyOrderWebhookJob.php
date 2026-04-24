<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Retail\BrandStoreSettings;
use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Customers\ContactCaptureService;
use App\Services\Store\BrandCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Processes a Shopify orders/paid webhook — identifies affiliate, calculates commission, creates ledger entries, and captures the customer as an affiliate contact.
class ProcessShopifyOrderWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $brandProfessionalId,
        public array $orderPayload,
    ) {
        $this->onQueue('integrations');
    }

    public function handle(ContactCaptureService $contactCapture, BrandCatalogService $catalogService): void
    {
        $orderId = (string) Arr::get($this->orderPayload, 'id', '');
        $noteAttributes = Arr::get($this->orderPayload, 'note_attributes', []);
        $lineItems = Arr::get($this->orderPayload, 'line_items', []);
        $currency = strtoupper(trim((string) Arr::get($this->orderPayload, 'currency', 'AUD')));
        $occurredAt = Arr::get($this->orderPayload, 'created_at', now()->toIso8601String());

        // Extract affiliate slug from cart attributes
        $affiliateSlug = $this->extractCartAttribute($noteAttributes, 'affiliate');

        if ($affiliateSlug === '') {
            Log::info('Shopify order webhook: no affiliate attribute, skipping', [
                'order_id' => $orderId,
                'brand_professional_id' => $this->brandProfessionalId,
            ]);

            return;
        }

        // Look up affiliate
        $affiliate = Professional::query()
            ->where('handle_lc', strtolower($affiliateSlug))
            ->first();

        if (! $affiliate) {
            Log::warning('Shopify order webhook: affiliate not found', [
                'order_id' => $orderId,
                'affiliate_slug' => $affiliateSlug,
            ]);

            return;
        }

        // Verify affiliate is connected to this brand
        $isConnected = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->where('brand_professional_id', $this->brandProfessionalId)
            ->exists();

        if (! $isConnected) {
            Log::warning('Shopify order webhook: affiliate not connected to brand', [
                'order_id' => $orderId,
                'affiliate_id' => (string) $affiliate->id,
                'brand_professional_id' => $this->brandProfessionalId,
            ]);

            return;
        }

        // Get brand settings and platform fallback for server-side rate resolution.
        $brandSettings = BrandStoreSettings::where('professional_id', $this->brandProfessionalId)->first();
        $platformDefault = (float) config('sidest.store.default_commission_rate', 15);

        // Collect distinct product GIDs from the order's line items. Shopify
        // sends a numeric product_id on the REST webhook payload; convert to GID
        // shape so we can query the Admin API (which only accepts GIDs via nodes()).
        $productGids = [];
        foreach ($lineItems as $li) {
            if (! is_array($li)) {
                continue;
            }
            $productId = (string) Arr::get($li, 'product_id', '');
            if ($productId !== '') {
                $productGids[] = "gid://shopify/Product/{$productId}";
            }
        }
        $productGids = array_values(array_unique($productGids));

        // Fetch commission_override metafield for each product in ONE Admin API
        // call. Buyer-set sidest_commission_rate line attributes are NOT used for
        // calculation — they're writable by the buyer via the Storefront Cart API
        // and therefore untrusted. We resolve the rate ourselves using the same
        // precedence the Hydrogen storefront applies.
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $this->brandProfessionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        $overrideMap = ($integration && ! empty($productGids))
            ? $catalogService->fetchCommissionOverridesForProducts($integration, $productGids)
            : [];

        // Phase 1: build candidates without touching the DB
        $candidates = [];
        foreach ($lineItems as $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }

            $lineItemId = (string) Arr::get($lineItem, 'id', '');
            $unitPrice = (float) Arr::get($lineItem, 'price', 0);
            $quantity = (int) Arr::get($lineItem, 'quantity', 1);

            if ($lineItemId === '' || $unitPrice <= 0 || $quantity <= 0) {
                continue;
            }

            // Commission base = what the customer actually paid for this line
            // (post-discount). Shopify's orders/paid webhook line_items shape:
            //   price             — pre-discount unit price
            //   quantity          — units in the line
            //   total_discount    — sum of every discount allocation applied to this line
            //                       (Side St Price Function discount included)
            //   discount_allocations[] — per-allocation breakdown (not needed here,
            //       but worth knowing if we ever need to differentiate our function
            //       from a manually-applied code on the same line).
            //
            // Using post-discount line total keeps commission aligned with the
            // brand's actual revenue on the sale — a 20% Side St Price discount
            // means the brand took home 20% less, and the affiliate's commission
            // on that line is 20% smaller too at the same commission rate.
            $lineTotalPreDiscount = $unitPrice * $quantity;
            $totalDiscount = (float) Arr::get($lineItem, 'total_discount', 0);
            $lineTotal = max(0.0, $lineTotalPreDiscount - $totalDiscount);

            if ($lineTotal <= 0) {
                // Line fully discounted (100% off, comp, etc.) — nothing to
                // accrue. Skip rather than emit a zero-cent entry.
                continue;
            }

            $productGid = ($productIdStr = (string) Arr::get($lineItem, 'product_id', '')) !== ''
                ? "gid://shopify/Product/{$productIdStr}"
                : '';

            [$commissionRate, $rateSource] = $this->resolveCommissionRate(
                $productGid,
                $overrideMap,
                $brandSettings,
                $platformDefault,
            );

            $commissionAmountCents = (int) round($lineTotal * ($commissionRate / 100) * 100);

            if ($commissionAmountCents <= 0) {
                continue;
            }

            // Audit trail: the buyer-submitted rate (may be empty or inflated).
            // Recorded verbatim so post-hoc investigations can detect tampering.
            $submittedRate = $this->extractSubmittedRate($lineItem);

            $candidates[] = [
                'idempotency_key' => "shopify_order_{$orderId}_line_{$lineItemId}",
                'data' => [
                    'shopify_order_id' => $orderId,
                    'brand_professional_id' => $this->brandProfessionalId,
                    'affiliate_professional_id' => (string) $affiliate->id,
                    'entry_type' => 'accrual',
                    'status' => 'approved',
                    'amount_cents' => $commissionAmountCents,
                    'currency_code' => $currency,
                    'commission_rate' => $commissionRate,
                    'rate_source' => $rateSource,
                    'idempotency_key' => "shopify_order_{$orderId}_line_{$lineItemId}",
                    'calculation_metadata' => [
                        'order_id' => $orderId,
                        'line_item_id' => $lineItemId,
                        'product_id' => (string) Arr::get($lineItem, 'product_id', ''),
                        // Keep both for audit: pre-discount line price (what the
                        // Shopify sticker was) plus the discount applied by
                        // any function/code, plus the post-discount figure we
                        // computed commission off.
                        'unit_price' => $unitPrice,
                        'line_price_pre_discount' => $lineTotalPreDiscount,
                        'total_discount' => $totalDiscount,
                        'line_price_post_discount' => $lineTotal,
                        'quantity' => $quantity,
                        'affiliate_slug' => $affiliateSlug,
                        'submitted_rate' => $submittedRate,
                    ],
                    'occurred_at' => $occurredAt,
                ],
            ];
        }

        // Phase 2: pre-filter existing idempotency keys (one query), then bulk-insert in one transaction.
        // Pre-filtering avoids a 23505 unique violation inside the transaction — PostgreSQL aborts the
        // entire transaction on constraint violations, so we can't catch-and-continue inside DB::transaction().
        $entriesCreated = 0;
        if (! empty($candidates)) {
            $candidateKeys = array_column($candidates, 'idempotency_key');
            $existingKeys = CommissionLedgerEntry::whereIn('idempotency_key', $candidateKeys)
                ->pluck('idempotency_key')
                ->flip()
                ->all();

            $newEntries = array_filter($candidates, fn ($c) => ! isset($existingKeys[$c['idempotency_key']]));

            DB::transaction(function () use ($newEntries, &$entriesCreated): void {
                foreach ($newEntries as $entry) {
                    CommissionLedgerEntry::create($entry['data']);
                    $entriesCreated++;
                }
            });
        }

        // Capture the buyer as an affiliate contact (Beta 3 — Contacts — Automatic Customer Capture).
        // Non-blocking: any failure inside the service is logged and swallowed so it can never
        // fail commission processing above.
        $this->captureAffiliateContact($contactCapture, (string) $affiliate->id, $noteAttributes, $orderId);

        // Rebuild commerce daily aggregates so dashboards and weekly notifications
        // reflect this order. Non-blocking — queued on the analytics worker.
        if ($entriesCreated > 0) {
            \App\Jobs\Analytics\RebuildCommerceDailyAggregatesJob::dispatch(
                $this->brandProfessionalId,
                (string) $affiliate->id,
                Carbon::parse($occurredAt)->toDateString()
            );
            \App\Jobs\Analytics\RebuildCommerceHourlyAggregatesJob::dispatch(
                $this->brandProfessionalId,
                (string) $affiliate->id,
                Carbon::parse($occurredAt)->utc()->startOfHour()->toIso8601String()
            );
        }

        Log::info('Shopify order webhook processed', [
            'order_id' => $orderId,
            'brand_professional_id' => $this->brandProfessionalId,
            'affiliate_id' => (string) $affiliate->id,
            'entries_created' => $entriesCreated,
        ]);
    }

    /**
     * Upsert the buyer into the affiliate's contacts list and, if the cart
     * carried a truthy `sidest_marketing_opt_in` attribute, add them to the
     * affiliate's marketing subscribers.
     *
     * Name resolution priority (matches the commit message): try
     * billing_address.name first (Shopify populates this with the full name
     * as entered at checkout), then fall back to customer.first_name +
     * customer.last_name from the Shopify customer record.
     *
     * @param  array<int, array<string, mixed>>|mixed  $noteAttributes
     */
    private function captureAffiliateContact(
        ContactCaptureService $contactCapture,
        string $affiliateId,
        mixed $noteAttributes,
        string $orderId,
    ): void {
        $customer = Arr::get($this->orderPayload, 'customer', []);
        $billingAddress = Arr::get($this->orderPayload, 'billing_address', []);

        $email = trim((string) Arr::get($customer, 'email', ''));
        if ($email === '') {
            // Shopify guest checkouts or POS orders without a customer — nothing to capture.
            return;
        }

        // Name: prefer billing_address.name, then fall back to customer first/last.
        $billingName = trim((string) Arr::get($billingAddress, 'name', ''));
        $firstName = trim((string) Arr::get($customer, 'first_name', ''));
        $lastName = trim((string) Arr::get($customer, 'last_name', ''));
        $fullName = $billingName !== '' ? $billingName : trim($firstName.' '.$lastName);
        $fullName = $fullName !== '' ? $fullName : null;

        $marketingConsent = $this->parseMarketingOptInAttribute($noteAttributes);

        // ContactCaptureService normalizes phone/full_name null-or-empty — we
        // can pass raw values through without pre-guarding.
        $contactCapture->captureContact($affiliateId, [
            'email' => $email,
            'full_name' => $fullName,
            'phone' => (string) Arr::get($billingAddress, 'phone', ''),
            'source' => 'shopify_order',
            'external_id' => $orderId !== '' ? $orderId : null,
            // Only override the service default when the shopper EXPLICITLY opted out.
            // Missing attribute -> null -> service default (true). Explicit "true" is
            // also null here because captureMarketingSubscription() handles it below.
            'marketing_opt_in' => $marketingConsent === false ? false : null,
        ]);

        // Truthy consent -> add them to the marketing list. Missing/falsy: the
        // contact is still captured above, just not subscribed for email blasts.
        if ($marketingConsent === true) {
            $contactCapture->captureMarketingSubscription(
                $affiliateId,
                $email,
                $fullName,
                'shopify_order',
            );
        }
    }

    /**
     * Parse the `sidest_marketing_opt_in` cart attribute into an explicit
     * tri-state: true (explicit opt-in), false (explicit opt-out), or null
     * (attribute missing / unrecognized value — let the schema default apply).
     *
     * Hydrogen is the only documented producer of this attribute. Recognized
     * values (case-insensitive):
     *   truthy:  'true' | '1' | 'yes'
     *   falsy:   'false' | '0' | 'no'
     *
     * Any other string is treated as "missing" so a typo doesn't silently
     * flip consent to false.
     *
     * @param  array<int, array<string, mixed>>|mixed  $noteAttributes
     */
    private function parseMarketingOptInAttribute(mixed $noteAttributes): ?bool
    {
        $raw = strtolower($this->extractCartAttribute($noteAttributes, 'sidest_marketing_opt_in'));
        if ($raw === '') {
            return null;
        }
        if (in_array($raw, ['true', '1', 'yes'], true)) {
            return true;
        }
        if (in_array($raw, ['false', '0', 'no'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Extract a value from Shopify note_attributes (cart-level attributes).
     */
    private function extractCartAttribute(mixed $noteAttributes, string $key): string
    {
        if (! is_array($noteAttributes)) {
            return '';
        }

        foreach ($noteAttributes as $attr) {
            if (is_array($attr) && strtolower(trim((string) ($attr['name'] ?? ''))) === $key) {
                return trim((string) ($attr['value'] ?? ''));
            }
        }

        return '';
    }

    /**
     * Resolve the commission rate for a line item, server-side. Precedence:
     *   1. product metafield `sidest.commission_override` (brand-set per-product)
     *   2. brand.brand_store_settings.default_commission_rate (brand default)
     *   3. config('sidest.store.default_commission_rate', 15) (platform fallback)
     *
     * @param  array<string, float|null>  $overrideMap
     * @return array{0: float, 1: string} [rate, rate_source]
     */
    private function resolveCommissionRate(
        string $productGid,
        array $overrideMap,
        ?BrandStoreSettings $brandSettings,
        float $platformDefault,
    ): array {
        if ($productGid !== '' && isset($overrideMap[$productGid]) && $overrideMap[$productGid] !== null) {
            $rate = (float) $overrideMap[$productGid];
            if ($rate > 0 && $rate <= 100) {
                return [$rate, 'metafield_override'];
            }
        }

        if ($brandSettings && $brandSettings->default_commission_rate !== null) {
            return [(float) $brandSettings->default_commission_rate, 'brand_default'];
        }

        return [$platformDefault, 'platform_default'];
    }

    /**
     * Pull the buyer-submitted sidest_commission_rate for the audit trail.
     * NOT used for calculation — returned verbatim so post-hoc analysis can
     * spot cart tampering or Hydrogen/webhook drift.
     */
    private function extractSubmittedRate(array $lineItem): ?string
    {
        $properties = Arr::get($lineItem, 'properties', []);
        if (! is_array($properties)) {
            return null;
        }
        foreach ($properties as $prop) {
            if (is_array($prop) && strtolower(trim((string) ($prop['name'] ?? ''))) === 'sidest_commission_rate') {
                return (string) ($prop['value'] ?? '');
            }
        }

        return null;
    }
}
