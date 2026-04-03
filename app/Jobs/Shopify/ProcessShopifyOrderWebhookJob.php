<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\BrandStoreSettings;
use App\Models\Retail\CommissionLedgerEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

// V2: Processes a Shopify orders/paid webhook — identifies affiliate, calculates commission, creates ledger entries.
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

    public function handle(): void
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

        // Get brand default commission rate as fallback
        $brandSettings = BrandStoreSettings::where('professional_id', $this->brandProfessionalId)->first();
        $defaultRate = $brandSettings ? (float) $brandSettings->default_commission_rate : 15.0;

        $entriesCreated = 0;

        foreach ($lineItems as $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }

            $lineItemId = (string) Arr::get($lineItem, 'id', '');
            $price = (float) Arr::get($lineItem, 'price', 0);
            $quantity = (int) Arr::get($lineItem, 'quantity', 1);

            if ($lineItemId === '' || $price <= 0 || $quantity <= 0) {
                continue;
            }

            // Extract commission rate from line item properties
            $commissionRate = $this->extractLineItemCommissionRate($lineItem, $defaultRate);

            // Calculate commission in cents
            $lineTotal = $price * $quantity;
            $commissionAmountCents = (int) round($lineTotal * ($commissionRate / 100) * 100);

            if ($commissionAmountCents <= 0) {
                continue;
            }

            $idempotencyKey = "shopify_order_{$orderId}_line_{$lineItemId}";

            try {
                CommissionLedgerEntry::create([
                    'shopify_order_id' => $orderId,
                    'brand_professional_id' => $this->brandProfessionalId,
                    'affiliate_professional_id' => (string) $affiliate->id,
                    'entry_type' => 'accrual',
                    'status' => 'approved',
                    'amount_cents' => $commissionAmountCents,
                    'currency_code' => $currency,
                    'commission_rate' => $commissionRate,
                    'rate_source' => 'cart_attribute',
                    'idempotency_key' => $idempotencyKey,
                    'calculation_metadata' => [
                        'order_id' => $orderId,
                        'line_item_id' => $lineItemId,
                        'product_id' => (string) Arr::get($lineItem, 'product_id', ''),
                        'line_price' => $price,
                        'quantity' => $quantity,
                        'affiliate_slug' => $affiliateSlug,
                    ],
                    'occurred_at' => $occurredAt,
                ]);

                $entriesCreated++;
            } catch (QueryException $e) {
                // Unique constraint violation on idempotency_key — duplicate, skip
                if ($e->getCode() === '23505') {
                    continue;
                }
                throw $e;
            }
        }

        Log::info('Shopify order webhook processed', [
            'order_id' => $orderId,
            'brand_professional_id' => $this->brandProfessionalId,
            'affiliate_id' => (string) $affiliate->id,
            'entries_created' => $entriesCreated,
        ]);
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
     * Extract sidest_commission_rate from line item properties, or use default.
     */
    private function extractLineItemCommissionRate(array $lineItem, float $defaultRate): float
    {
        $properties = Arr::get($lineItem, 'properties', []);

        if (is_array($properties)) {
            foreach ($properties as $prop) {
                if (is_array($prop) && strtolower(trim((string) ($prop['name'] ?? ''))) === 'sidest_commission_rate') {
                    $rate = (float) ($prop['value'] ?? 0);
                    if ($rate > 0 && $rate <= 100) {
                        return $rate;
                    }
                }
            }
        }

        return $defaultRate;
    }
}
