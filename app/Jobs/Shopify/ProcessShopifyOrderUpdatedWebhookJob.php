<?php

namespace App\Jobs\Shopify;

use App\Models\Commerce\Order;
use App\Models\Commerce\OrderEvent;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\AnalyticsCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Phase 3: Handles orders/updated, orders/edited, orders/cancelled, refunds/create topics.
// Routes each topic to the appropriate snapshot or status-update path.
// commission_cents and commission_rate are FROZEN at orders/paid time (Decision #3).
// The trg_rollup_clawback trigger handles refund deltas in brand_affiliate_rollup automatically.
class ProcessShopifyOrderUpdatedWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $professionalId,
        public array $payload,
        public string $topic,
        public string $shopifyEventId = '',
    ) {
        $this->onQueue('integrations');
    }

    public function handle(AnalyticsCacheService $analyticsCache): void
    {
        match ($this->topic) {
            'orders/updated' => $this->handleUpdated($analyticsCache),
            'orders/edited' => $this->handleEdited($analyticsCache),
            'orders/cancelled' => $this->handleCancelled($analyticsCache),
            'refunds/create' => $this->handleRefund($analyticsCache),
            default => Log::warning('ProcessShopifyOrderUpdatedWebhookJob: unknown topic', [
                'topic' => $this->topic,
                'professional_id' => $this->professionalId,
            ]),
        };
    }

    public function failed(\Throwable $e): void
    {
        report($e);

        // refunds/create uses order_id for the parent order; all other topics use id.
        $shopifyOrderId = (string) (Arr::get($this->payload, 'id') ?? Arr::get($this->payload, 'order_id', ''));

        Log::error('ProcessShopifyOrderUpdatedWebhookJob exhausted all retries', [
            'professional_id' => $this->professionalId,
            'topic' => $this->topic,
            'shopify_order_id' => $shopifyOrderId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * orders/updated — LWW snapshot update. Updates gross, refund, status, and raw data.
     * commission_cents / commission_rate are intentionally NOT updated (frozen at paid time).
     */
    private function handleUpdated(AnalyticsCacheService $analyticsCache): void
    {
        $shopifyOrderId = (string) Arr::get($this->payload, 'id', '');
        $shopDomain = $this->resolveShopDomain();
        $shopifyUpdatedAt = Arr::get($this->payload, 'updated_at', now()->toIso8601String());

        if ($shopifyOrderId === '' || $shopDomain === '') {
            return;
        }

        $order = $this->findOrder($shopifyOrderId, $shopDomain);
        if (! $order) {
            // orders/updated with no existing row: stub insertion is NOT done here.
            // Decision: only orders/paid creates a stub-free authoritative row;
            // updated snapshots rely on the order existing. Skip + let reconciler fill gaps.
            return;
        }

        $affiliateId = (string) $order->affiliate_professional_id;

        // orders/updated: snapshot only — no event row written.
        // The LWW WHERE guard on shopify_updated_at makes duplicate deliveries idempotent.
        // 'updated' and 'snapshot' are not in the event_type CHECK constraint; only the
        // canonical lifecycle events (paid, edited, cancelled, refunded, etc.) are recorded.
        $this->snapshotUpdate($order, $shopifyUpdatedAt, 'updated');

        $analyticsCache->invalidateAnalytics($this->professionalId);
        $analyticsCache->invalidateAnalytics($affiliateId);
    }

    /**
     * orders/edited — snapshot update, commission frozen (Decision #3).
     * On first-seen, inserts a stub with shopify_updated_at = event_time - 1ms so
     * the eventual orders/paid (with later shopify_updated_at) wins LWW.
     * Skips if affiliate cannot be resolved from note_attributes.
     */
    private function handleEdited(AnalyticsCacheService $analyticsCache): void
    {
        $shopifyOrderId = (string) Arr::get($this->payload, 'id', '');
        $shopDomain = $this->resolveShopDomain();
        $shopifyUpdatedAt = Arr::get($this->payload, 'updated_at', now()->toIso8601String());

        if ($shopifyOrderId === '' || $shopDomain === '') {
            return;
        }

        $order = $this->findOrder($shopifyOrderId, $shopDomain);

        if (! $order) {
            // First-seen: insert stub so event has a parent FK. If affiliate is missing, skip.
            $affiliateId = $this->resolveAffiliateIdFromPayload();
            if ($affiliateId === null) {
                Log::warning('ProcessShopifyOrderUpdatedWebhookJob: orders/edited first-seen, affiliate not resolvable — skipping stub', [
                    'shopify_order_id' => $shopifyOrderId,
                    'professional_id' => $this->professionalId,
                ]);

                return;
            }

            // stub shopify_updated_at is 1ms before event time so orders/paid wins LWW.
            $stubUpdatedAt = (new \Carbon\Carbon($shopifyUpdatedAt))->subMillisecond()->toIso8601String();

            $order = $this->insertStub(
                shopifyOrderId: $shopifyOrderId,
                shopDomain: $shopDomain,
                affiliateProfessionalId: $affiliateId,
                status: 'stub',
                shopifyUpdatedAt: $stubUpdatedAt,
                occurredAt: $shopifyUpdatedAt,
            );

            if (! $order) {
                return;
            }
        }

        // For existing rows (non-stub): update snapshot fields only. Commission frozen.
        // For stub rows: the snapshot values are empty — don't overwrite with zeroes.
        if ($order->status !== 'stub') {
            $this->snapshotUpdate($order, $shopifyUpdatedAt, 'edited');
        }

        $affiliateId = (string) $order->affiliate_professional_id;
        $this->insertEventIfNew($order->id, 'edited', $this->shopifyEventId, [], $shopifyUpdatedAt);

        $analyticsCache->invalidateAnalytics($this->professionalId);
        $analyticsCache->invalidateAnalytics($affiliateId);
    }

    /**
     * orders/cancelled — set status='cancelled'. First-seen inserts stub with status='cancelled'.
     * Skips if affiliate cannot be resolved.
     */
    private function handleCancelled(AnalyticsCacheService $analyticsCache): void
    {
        $shopifyOrderId = (string) Arr::get($this->payload, 'id', '');
        $shopDomain = $this->resolveShopDomain();
        $shopifyUpdatedAt = Arr::get($this->payload, 'updated_at', now()->toIso8601String());

        if ($shopifyOrderId === '' || $shopDomain === '') {
            return;
        }

        $order = $this->findOrder($shopifyOrderId, $shopDomain);

        if (! $order) {
            $affiliateId = $this->resolveAffiliateIdFromPayload();
            if ($affiliateId === null) {
                Log::warning('ProcessShopifyOrderUpdatedWebhookJob: orders/cancelled first-seen, affiliate not resolvable — skipping stub', [
                    'shopify_order_id' => $shopifyOrderId,
                    'professional_id' => $this->professionalId,
                ]);

                return;
            }

            $stubUpdatedAt = (new \Carbon\Carbon($shopifyUpdatedAt))->subMillisecond()->toIso8601String();
            $order = $this->insertStub(
                shopifyOrderId: $shopifyOrderId,
                shopDomain: $shopDomain,
                affiliateProfessionalId: $affiliateId,
                status: 'cancelled',
                shopifyUpdatedAt: $stubUpdatedAt,
                occurredAt: $shopifyUpdatedAt,
            );

            if (! $order) {
                return;
            }
        } else {
            // LWW guard: only update if the incoming timestamp is newer.
            // Using Eloquent update to remain database-agnostic (avoids PG-only ::cast syntax).
            Order::query()
                ->where('shopify_shop_domain', $shopDomain)
                ->where('shopify_order_id', $shopifyOrderId)
                ->where('shopify_updated_at', '<', $shopifyUpdatedAt)
                ->update([
                    'status' => 'cancelled',
                    'shopify_updated_at' => $shopifyUpdatedAt,
                    'updated_at' => now(),
                ]);

            $order->refresh();
        }

        $affiliateId = (string) $order->affiliate_professional_id;
        $this->insertEventIfNew($order->id, 'cancelled', $this->shopifyEventId, [], $shopifyUpdatedAt);

        $analyticsCache->invalidateAnalytics($this->professionalId);
        $analyticsCache->invalidateAnalytics($affiliateId);
    }

    /**
     * refunds/create — update refund_cents (cumulative sum of all refunds), set status.
     * The trg_rollup_clawback trigger fires on refund_cents change and updates brand_affiliate_rollup.
     * On first-seen (Race 2): insert stub with status='stub', refund_cents=<this refund>,
     * so the eventual orders/paid can overwrite via LWW when it arrives with a later shopify_updated_at.
     * Skips if affiliate cannot be resolved.
     */
    private function handleRefund(AnalyticsCacheService $analyticsCache): void
    {
        $shopifyOrderId = (string) Arr::get($this->payload, 'order_id', '');
        $shopDomain = $this->resolveShopDomain();
        $refundCreatedAt = Arr::get($this->payload, 'created_at', now()->toIso8601String());

        // Refund payload uses order_id for the parent order reference.
        if ($shopifyOrderId === '' || $shopDomain === '') {
            return;
        }

        $refundId = (string) Arr::get($this->payload, 'id', '');
        $refundNote = (string) Arr::get($this->payload, 'note', '');

        // Calculate the total amount refunded in this refund object.
        $refundSubtotal = $this->calculateRefundSubtotal();

        $order = $this->findOrder($shopifyOrderId, $shopDomain);

        if (! $order) {
            // Race 2: refund arrived before orders/paid. Insert stub.
            $affiliateId = $this->resolveAffiliateIdFromPayload();
            if ($affiliateId === null) {
                Log::warning('ProcessShopifyOrderUpdatedWebhookJob: refunds/create first-seen, affiliate not resolvable — skipping stub', [
                    'shopify_order_id' => $shopifyOrderId,
                    'professional_id' => $this->professionalId,
                ]);

                return;
            }

            // shopify_updated_at = refund.created_at - 1ms so orders/paid wins LWW.
            $stubUpdatedAt = (new \Carbon\Carbon($refundCreatedAt))->subMillisecond()->toIso8601String();

            $order = $this->insertStubWithRefund(
                shopifyOrderId: $shopifyOrderId,
                shopDomain: $shopDomain,
                affiliateProfessionalId: $affiliateId,
                refundCents: $refundSubtotal,
                shopifyUpdatedAt: $stubUpdatedAt,
                occurredAt: $refundCreatedAt,
            );

            if (! $order) {
                return;
            }
        } else {
            // Update refund_cents (cumulative sum) and derive new status.
            // gross_cents is the authoritative order total; refund_cents tracks cumulative refunds.
            // Using raw SQL here because Eloquent has no built-in increment for a subset of records.
            // This SQL uses standard (non-PG-specific) syntax — compatible with SQLite for tests.
            DB::connection('pgsql')->statement(
                'UPDATE commerce.orders
                SET refund_cents = refund_cents + ?,
                    status = CASE
                        WHEN (refund_cents + ?) >= gross_cents THEN ? ELSE ? END,
                    updated_at = ?
                WHERE shopify_shop_domain = ? AND shopify_order_id = ?',
                [$refundSubtotal, $refundSubtotal, 'refunded', 'partially_refunded', now()->toDateTimeString(), $shopDomain, $shopifyOrderId],
            );

            $order->refresh();
        }

        $affiliateId = (string) $order->affiliate_professional_id;

        // Derive the event type from the order status after the update.
        // For stubs (gross_cents=0), we don't know full/partial yet — use partially_refunded.
        $refundEventType = ($order->status === 'refunded') ? 'refunded' : 'partially_refunded';

        // Event metadata: only non-PII refund context. refund.note is a GDPR-tracked path.
        $metadata = array_filter([
            'refund_id' => $refundId,
            'refund_note' => $refundNote !== '' ? $refundNote : null,
            'refund_subtotal_cents' => $refundSubtotal,
        ]);

        $this->insertEventIfNew($order->id, $refundEventType, $this->shopifyEventId, $metadata, $refundCreatedAt);

        $analyticsCache->invalidateAnalytics($this->professionalId);
        $analyticsCache->invalidateAnalytics($affiliateId);
    }

    /**
     * LWW snapshot update for gross_cents, shopify_data, line_items, shopify_updated_at.
     * commission_cents and commission_rate are intentionally excluded (frozen at paid time — Decision #3).
     * Uses Eloquent update with PHP-side LWW guard to remain database-agnostic.
     */
    private function snapshotUpdate(Order $order, string $shopifyUpdatedAt, string $topic): void
    {
        $shopifyData = $this->buildSafeShopifyData($this->payload);
        $lineItems = Arr::get($this->payload, 'line_items', []);
        $grossCents = $this->calculateGrossCents($lineItems);
        $refundCents = $this->calculateTotalRefundCentsFromPayload();

        // LWW: only update if incoming shopify_updated_at is strictly newer than the stored value.
        // Eloquent where('<', ...) provides the guard without PG-specific SQL.
        Order::query()
            ->where('shopify_shop_domain', (string) $order->shopify_shop_domain)
            ->where('shopify_order_id', (string) $order->shopify_order_id)
            ->where('shopify_updated_at', '<', $shopifyUpdatedAt)
            ->update([
                'gross_cents' => $grossCents,
                'refund_cents' => $refundCents,
                'line_items' => json_encode($lineItems),
                'shopify_data' => json_encode($shopifyData),
                'shopify_updated_at' => $shopifyUpdatedAt,
                'updated_at' => now(),
            ]);
    }

    /**
     * Insert an order_events row, catching UniqueConstraintViolationException (idempotency).
     */
    private function insertEventIfNew(
        string $orderId,
        string $eventType,
        string $shopifyEventId,
        array $metadata,
        string $shopifyTriggeredAt,
    ): void {
        try {
            (new OrderEvent)->forceFill([
                'order_id' => $orderId,
                'event_type' => $eventType,
                'source' => 'webhook',
                'shopify_event_id' => $shopifyEventId !== '' ? $shopifyEventId : null,
                'shopify_triggered_at' => $shopifyTriggeredAt,
                'metadata' => $metadata ?: [],
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Already recorded — idempotent no-op.
        }
    }

    /**
     * Atomically insert a stub order row using INSERT ... ON CONFLICT DO NOTHING.
     * Eliminates the TOCTOU race of exists() + save() — concurrent inserts are safe.
     * Returns the loaded Order model on success (whether the INSERT created a new row or was skipped), null on error.
     * Stubs have commission_cents=0, commission_rate=0, rate_source='pending'.
     */
    private function insertStub(
        string $shopifyOrderId,
        string $shopDomain,
        string $affiliateProfessionalId,
        string $status,
        string $shopifyUpdatedAt,
        string $occurredAt,
    ): ?Order {
        try {
            $this->atomicStubInsert(
                shopifyOrderId: $shopifyOrderId,
                shopDomain: $shopDomain,
                affiliateProfessionalId: $affiliateProfessionalId,
                status: $status,
                refundCents: 0,
                shopifyUpdatedAt: $shopifyUpdatedAt,
                occurredAt: $occurredAt,
            );
        } catch (\Throwable $e) {
            Log::warning('ProcessShopifyOrderUpdatedWebhookJob: stub insert failed', [
                'shopify_order_id' => $shopifyOrderId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->findOrder($shopifyOrderId, $shopDomain);
    }

    /**
     * Insert a stub for Race 2 (refund before paid). Sets refund_cents to this refund's amount.
     */
    private function insertStubWithRefund(
        string $shopifyOrderId,
        string $shopDomain,
        string $affiliateProfessionalId,
        int $refundCents,
        string $shopifyUpdatedAt,
        string $occurredAt,
    ): ?Order {
        try {
            $this->atomicStubInsert(
                shopifyOrderId: $shopifyOrderId,
                shopDomain: $shopDomain,
                affiliateProfessionalId: $affiliateProfessionalId,
                status: 'stub',
                refundCents: $refundCents,
                shopifyUpdatedAt: $shopifyUpdatedAt,
                occurredAt: $occurredAt,
            );
        } catch (\Throwable $e) {
            Log::warning('ProcessShopifyOrderUpdatedWebhookJob: stub-with-refund insert failed', [
                'shopify_order_id' => $shopifyOrderId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->findOrder($shopifyOrderId, $shopDomain);
    }

    private function findOrder(string $shopifyOrderId, string $shopDomain): ?Order
    {
        return Order::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('shopify_order_id', $shopifyOrderId)
            ->first();
    }

    /**
     * Atomically insert a stub row, ignoring conflicts on (shopify_shop_domain, shopify_order_id).
     * Uses INSERT OR IGNORE (SQLite) or INSERT ... ON CONFLICT DO NOTHING (pgsql) — both are atomic.
     * No return value; callers use findOrder() to retrieve the winner row.
     */
    private function atomicStubInsert(
        string $shopifyOrderId,
        string $shopDomain,
        string $affiliateProfessionalId,
        string $status,
        int $refundCents,
        string $shopifyUpdatedAt,
        string $occurredAt,
    ): void {
        $id = (string) \Illuminate\Support\Str::uuid();
        $now = now()->toDateTimeString();
        $lineItems = '[]';
        $shopifyData = '{}';

        $driver = DB::connection('pgsql')->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: INSERT OR IGNORE is the equivalent of ON CONFLICT DO NOTHING.
            DB::connection('pgsql')->statement(
                'INSERT OR IGNORE INTO commerce.orders
                    (id, shopify_order_id, shopify_shop_domain, brand_professional_id,
                     affiliate_professional_id, status, gross_cents, discount_cents,
                     refund_cents, net_cents, commission_cents, commission_rate,
                     rate_source, currency_code, line_items, shopify_data,
                     shopify_updated_at, occurred_at, created_at, updated_at)
                VALUES (?,?,?,?,?,?,0,0,?,0,0,0,\'pending\',\'AUD\',?,?,?,?,?,?)',
                [$id, $shopifyOrderId, $shopDomain, $this->professionalId,
                    $affiliateProfessionalId, $status, $refundCents, $lineItems, $shopifyData,
                    $shopifyUpdatedAt, $occurredAt, $now, $now],
            );
        } else {
            // pgsql: standard ON CONFLICT DO NOTHING.
            DB::connection('pgsql')->statement(
                'INSERT INTO commerce.orders
                    (id, shopify_order_id, shopify_shop_domain, brand_professional_id,
                     affiliate_professional_id, status, gross_cents, discount_cents,
                     refund_cents, net_cents, commission_cents, commission_rate,
                     rate_source, currency_code, line_items, shopify_data,
                     shopify_updated_at, occurred_at, created_at, updated_at)
                VALUES (?,?,?,?,?,?,0,0,?,0,0,0,\'pending\',\'AUD\',?,?,?,?,?,?)
                ON CONFLICT (shopify_shop_domain, shopify_order_id) DO NOTHING',
                [$id, $shopifyOrderId, $shopDomain, $this->professionalId,
                    $affiliateProfessionalId, $status, $refundCents, $lineItems, $shopifyData,
                    $shopifyUpdatedAt, $occurredAt, $now, $now],
            );
        }
    }

    /**
     * Resolve shop domain from the integration record (authoritative for all topics).
     * Falls back to payload domain field.
     */
    private function resolveShopDomain(): string
    {
        $integration = \App\Models\Core\Professional\ProfessionalIntegration::query()
            ->where('professional_id', $this->professionalId)
            ->where('provider', \App\Models\Core\Professional\ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->value('shopify_shop_domain');

        if ($integration) {
            return (string) $integration;
        }

        // Fallback: try to get domain from payload (some topics include it)
        $domain = strtolower(trim((string) Arr::get($this->payload, 'domain', '')));

        return $domain;
    }

    /**
     * Attempt to resolve affiliate professional_id from note_attributes in the payload.
     * Returns null if not found — callers skip stub insertion on null.
     */
    private function resolveAffiliateIdFromPayload(): ?string
    {
        $noteAttributes = Arr::get($this->payload, 'note_attributes', []);
        $affiliateSlug = $this->extractCartAttribute($noteAttributes, 'affiliate');

        if ($affiliateSlug === '') {
            return null;
        }

        $affiliate = Professional::query()
            ->where('handle_lc', strtolower($affiliateSlug))
            ->first();

        if (! $affiliate) {
            return null;
        }

        return (string) $affiliate->id;
    }

    /**
     * Calculate total gross_cents from line_items (post-discount line totals).
     *
     * @param  array<int, mixed>  $lineItems
     */
    private function calculateGrossCents(array $lineItems): int
    {
        $total = 0;
        foreach ($lineItems as $li) {
            if (! is_array($li)) {
                continue;
            }
            $unitPrice = (float) Arr::get($li, 'price', 0);
            $quantity = (int) Arr::get($li, 'quantity', 1);
            $discount = (float) Arr::get($li, 'total_discount', 0);
            $lineCents = (int) round($unitPrice * $quantity * 100) - (int) round($discount * 100);
            $total += max(0, $lineCents);
        }

        return $total;
    }

    /**
     * Calculate total refund subtotal in cents from this refund/create payload.
     */
    private function calculateRefundSubtotal(): int
    {
        $total = 0;
        $refundLineItems = Arr::get($this->payload, 'refund_line_items', []);
        if (! is_array($refundLineItems)) {
            return 0;
        }
        foreach ($refundLineItems as $rli) {
            if (! is_array($rli)) {
                continue;
            }
            $subtotal = (float) Arr::get($rli, 'subtotal', 0);
            $total += (int) round($subtotal * 100);
        }

        return $total;
    }

    /**
     * Calculate total refund_cents from all refunds[] in an orders/updated payload.
     */
    private function calculateTotalRefundCentsFromPayload(): int
    {
        $total = 0;
        $refunds = Arr::get($this->payload, 'refunds', []);
        if (! is_array($refunds)) {
            return 0;
        }
        foreach ($refunds as $refund) {
            if (! is_array($refund)) {
                continue;
            }
            $refundLines = Arr::get($refund, 'refund_line_items', []);
            if (! is_array($refundLines)) {
                continue;
            }
            foreach ($refundLines as $rli) {
                if (! is_array($rli)) {
                    continue;
                }
                $total += (int) round((float) Arr::get($rli, 'subtotal', 0) * 100);
            }
        }

        return $total;
    }

    /**
     * Build a PII-safe shopify_data snapshot (no customer/address PII).
     */
    private function buildSafeShopifyData(array $payload): array
    {
        $safe = [
            'id' => Arr::get($payload, 'id'),
            'name' => Arr::get($payload, 'name'),
            'financial_status' => Arr::get($payload, 'financial_status'),
            'fulfillment_status' => Arr::get($payload, 'fulfillment_status'),
            'total_price' => Arr::get($payload, 'total_price'),
            'subtotal_price' => Arr::get($payload, 'subtotal_price'),
            'total_discounts' => Arr::get($payload, 'total_discounts'),
            'currency' => Arr::get($payload, 'currency'),
            'updated_at' => Arr::get($payload, 'updated_at'),
        ];

        return array_filter($safe, fn ($v) => $v !== null);
    }

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
}
