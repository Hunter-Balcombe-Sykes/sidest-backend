<?php

namespace App\Jobs\Shopify;

use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

// V2: Processes orders/updated webhooks — handles full refunds, partial refunds, and cancellations by reversing commission ledger entries.
class ProcessShopifyOrderUpdatedWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $professionalId,
        public array $payload,
    ) {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        $orderId = (string) Arr::get($this->payload, 'id', '');
        $financialStatus = strtolower(trim((string) Arr::get($this->payload, 'financial_status', '')));

        if ($orderId === '' || $financialStatus === '') {
            return;
        }

        // Only act on refund/cancel statuses
        if (! in_array($financialStatus, ['refunded', 'voided', 'partially_refunded'], true)) {
            return;
        }

        // Find existing accrual entries for this order
        $accruals = CommissionLedgerEntry::query()
            ->where('shopify_order_id', $orderId)
            ->where('entry_type', 'accrual')
            ->get();

        if ($accruals->isEmpty()) {
            return;
        }

        if ($financialStatus === 'partially_refunded') {
            $this->handlePartialRefund($orderId, $accruals);
        } else {
            $this->handleFullRefund($orderId, $accruals);
        }
    }

    private function handleFullRefund(string $orderId, $accruals): void
    {
        $reversed = CommissionLedgerEntry::query()
            ->where('shopify_order_id', $orderId)
            ->where('entry_type', 'accrual')
            ->where('status', 'approved')
            ->update(['status' => 'reversed', 'updated_at' => now()]);

        if ($reversed > 0) {
            $this->notifyAffiliatesOfRefund($orderId, $accruals, fullRefund: true);
        }

        Log::info('Shopify order fully refunded/cancelled — commissions reversed.', [
            'professional_id' => $this->professionalId,
            'order_id' => $orderId,
            'entries_reversed' => $reversed,
        ]);
    }

    private function handlePartialRefund(string $orderId, $accruals): void
    {
        $refunds = Arr::get($this->payload, 'refunds', []);

        if (! is_array($refunds)) {
            return;
        }

        // Index accruals by line_item_id for fast lookup
        $accrualsByLineItem = [];
        foreach ($accruals as $accrual) {
            $meta = is_array($accrual->calculation_metadata) ? $accrual->calculation_metadata : [];
            $lineItemId = (string) ($meta['line_item_id'] ?? '');
            if ($lineItemId !== '') {
                $accrualsByLineItem[$lineItemId] = $accrual;
            }
        }

        $reversalsCreated = 0;

        foreach ($refunds as $refund) {
            if (! is_array($refund)) {
                continue;
            }

            $refundId = (string) Arr::get($refund, 'id', '');
            $refundLineItems = Arr::get($refund, 'refund_line_items', []);

            if ($refundId === '' || ! is_array($refundLineItems)) {
                continue;
            }

            foreach ($refundLineItems as $refundLine) {
                if (! is_array($refundLine)) {
                    continue;
                }

                $lineItemId = (string) Arr::get($refundLine, 'line_item_id', '');
                $refundSubtotal = (float) Arr::get($refundLine, 'subtotal', 0);

                if ($lineItemId === '' || $refundSubtotal <= 0) {
                    continue;
                }

                $originalAccrual = $accrualsByLineItem[$lineItemId] ?? null;

                if (! $originalAccrual || $originalAccrual->status !== 'approved') {
                    continue;
                }

                // Calculate reversal amount using the original commission rate
                $commissionRate = (float) $originalAccrual->commission_rate;
                $reversalCents = (int) round($refundSubtotal * ($commissionRate / 100) * 100);

                if ($reversalCents <= 0) {
                    continue;
                }

                $idempotencyKey = "shopify_order_{$orderId}_refund_{$refundId}_line_{$lineItemId}";

                try {
                    CommissionLedgerEntry::create([
                        'shopify_order_id' => $orderId,
                        'brand_professional_id' => (string) $originalAccrual->brand_professional_id,
                        'affiliate_professional_id' => (string) $originalAccrual->affiliate_professional_id,
                        'entry_type' => 'reversal',
                        'status' => 'approved',
                        'amount_cents' => -$reversalCents,
                        'currency_code' => $originalAccrual->currency_code,
                        'commission_rate' => $commissionRate,
                        'rate_source' => 'refund',
                        'idempotency_key' => $idempotencyKey,
                        'calculation_metadata' => [
                            'order_id' => $orderId,
                            'refund_id' => $refundId,
                            'line_item_id' => $lineItemId,
                            'refund_subtotal' => $refundSubtotal,
                            'original_accrual_id' => (string) $originalAccrual->id,
                        ],
                        'occurred_at' => now(),
                    ]);

                    $reversalsCreated++;
                } catch (QueryException $e) {
                    // Unique constraint violation — already processed this refund line
                    if ($e->getCode() === '23505') {
                        continue;
                    }
                    throw $e;
                }
            }
        }

        if ($reversalsCreated > 0) {
            $this->notifyAffiliatesOfRefund($orderId, $accruals, fullRefund: false);
        }

        Log::info('Shopify order partially refunded — reversal entries created.', [
            'professional_id' => $this->professionalId,
            'order_id' => $orderId,
            'reversals_created' => $reversalsCreated,
        ]);
    }

    private function notifyAffiliatesOfRefund(string $orderId, $accruals, bool $fullRefund): void
    {
        $publisher = app(NotificationPublisher::class);

        $affiliateIds = $accruals
            ->pluck('affiliate_professional_id')
            ->filter()
            ->unique()
            ->values();

        foreach ($affiliateIds as $affiliateId) {
            $affiliateAccruals = $accruals->where('affiliate_professional_id', $affiliateId);
            $totalCents = $affiliateAccruals->sum('amount_cents');
            $currency = $affiliateAccruals->first()->currency_code ?? 'AUD';
            $formatted = strtoupper($currency) . ' $' . number_format($totalCents / 100, 2);

            $body = $fullRefund
                ? "A sale was refunded — your {$formatted} commission has been cancelled."
                : "A sale was partially refunded — your commission has been adjusted.";

            $publisher->publish(
                professionalId: (string) $affiliateId,
                frontendType: 'Warning',
                category: 'commissions',
                title: 'Commission ' . ($fullRefund ? 'cancelled' : 'adjusted'),
                body: $body,
                dedupeKey: "refund.order.{$orderId}.affiliate.{$affiliateId}",
                ctaUrl: '/account/commissions',
                primaryActionLabel: 'View Commissions',
            );
        }
    }
}
