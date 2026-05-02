<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
        if (! Professional::find($this->professionalId)) {
            Log::warning('ProcessShopifyOrderUpdatedWebhookJob: brand professional not found, skipping', [
                'professional_id' => $this->professionalId,
            ]);

            return;
        }

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

        // Rebuild commerce daily aggregates so refunded_cents and commission_reversed_cents
        // are reflected in dashboards and weekly notifications.
        $this->dispatchCommerceRebuilds($accruals);
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

        // Phase 1: flatten nested refund loops into candidates without touching the DB
        $candidates = [];
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

                $commissionRate = (float) $originalAccrual->commission_rate;
                $reversalCents = (int) round($refundSubtotal * ($commissionRate / 100) * 100);

                if ($reversalCents <= 0) {
                    continue;
                }

                $candidates[] = [
                    'idempotency_key' => "shopify_order_{$orderId}_refund_{$refundId}_line_{$lineItemId}",
                    'data' => [
                        'shopify_order_id' => $orderId,
                        'brand_professional_id' => (string) $originalAccrual->brand_professional_id,
                        'affiliate_professional_id' => (string) $originalAccrual->affiliate_professional_id,
                        'entry_type' => 'reversal',
                        'status' => 'approved',
                        'amount_cents' => -$reversalCents,
                        'currency_code' => $originalAccrual->currency_code,
                        'commission_rate' => $commissionRate,
                        'rate_source' => 'refund',
                        'idempotency_key' => "shopify_order_{$orderId}_refund_{$refundId}_line_{$lineItemId}",
                        'calculation_metadata' => [
                            'order_id' => $orderId,
                            'refund_id' => $refundId,
                            'line_item_id' => $lineItemId,
                            'refund_subtotal' => $refundSubtotal,
                            'original_accrual_id' => (string) $originalAccrual->id,
                        ],
                        'occurred_at' => now(),
                    ],
                ];
            }
        }

        // Phase 2: pre-filter duplicates in one query, then insert in one transaction.
        $reversalsCreated = 0;
        if (! empty($candidates)) {
            $candidateKeys = array_column($candidates, 'idempotency_key');
            $existingKeys = CommissionLedgerEntry::whereIn('idempotency_key', $candidateKeys)
                ->pluck('idempotency_key')
                ->flip()
                ->all();

            $newEntries = array_filter($candidates, fn ($c) => ! isset($existingKeys[$c['idempotency_key']]));

            // Pre-fetch affiliates so the observer's notifyBrandSale() doesn't lazy-load
            // affiliateProfessional for each reversal entry (N+1 on display_name).
            // setRelation pre-sets below survive the observer's afterCommit dispatch —
            // Laravel keeps object identity (no clone) for synchronous post-commit callbacks.
            $affiliateIds = collect($newEntries)
                ->pluck('data.affiliate_professional_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $affiliatesById = Professional::query()
                ->whereIn('id', $affiliateIds)
                ->get(['id', 'display_name'])
                ->keyBy('id');

            DB::transaction(function () use ($newEntries, $affiliatesById, &$reversalsCreated): void {
                foreach ($newEntries as $entry) {
                    $row = (new CommissionLedgerEntry)->forceFill($entry['data']);
                    $affiliate = $affiliatesById->get($entry['data']['affiliate_professional_id'] ?? null);
                    if ($affiliate) {
                        $row->setRelation('affiliateProfessional', $affiliate);
                    }
                    $row->save();
                    $reversalsCreated++;
                }
            });
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

    private function dispatchCommerceRebuilds($accruals): void
    {
        $pairs = $accruals
            ->groupBy(fn ($e) => $e->brand_professional_id.'|'.$e->affiliate_professional_id);

        foreach ($pairs as $entries) {
            $entry = $entries->first();

            if (! $entry->occurred_at) {
                continue;
            }

            \App\Jobs\Analytics\RebuildCommerceDailyAggregatesJob::dispatch(
                (string) $entry->brand_professional_id,
                (string) $entry->affiliate_professional_id,
                $entry->occurred_at->toDateString()
            );
            \App\Jobs\Analytics\RebuildCommerceHourlyAggregatesJob::dispatch(
                (string) $entry->brand_professional_id,
                (string) $entry->affiliate_professional_id,
                $entry->occurred_at->utc()->startOfHour()->toIso8601String()
            );
        }
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
            $formatted = strtoupper($currency).' $'.number_format($totalCents / 100, 2);

            $body = $fullRefund
                ? "A sale was refunded — your {$formatted} commission has been cancelled."
                : 'A sale was partially refunded — your commission has been adjusted.';

            $publisher->publish(
                professionalId: (string) $affiliateId,
                frontendType: 'Warning',
                category: 'commissions',
                title: 'Commission '.($fullRefund ? 'cancelled' : 'adjusted'),
                body: $body,
                dedupeKey: "refund.order.{$orderId}.affiliate.{$affiliateId}",
                ctaUrl: '/account/commissions',
                primaryActionLabel: 'View Commissions',
            );
        }
    }
}
