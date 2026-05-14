<?php

namespace App\Services\Stripe;

use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

// V2: Pulls Stripe-side transaction data scoped to a brand or affiliate, normalised into the
// uniform row shape consumed by TransactionResource.
//
// Why scope via commerce.commission_payouts rather than Stripe's list endpoints:
// - The v2 PaymentIntent filter for "customer-account = X" is still settling between beta and GA
//   variants (`customer_account` vs `customer.account`). Listing via our local payout table
//   gives a stable scope while still reading transaction details live from Stripe.
// - We already store payment_intent_id + charge_id on every payout, so the per-payout fetch
//   is a single retrieve with expand, not a list call.
// - Cross-references (counterparty name, payout ID, orders count) come from the local row
//   without round-trips.
//
// Cache wrapping is the controller's job (CacheLockService::rememberLocked) — this service
// stays pure for testability.
class StripeTransactionFetcher
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * @return array<int, array<string, mixed>> rows shaped for TransactionResource
     */
    public function forBrand(Professional $brand, array $filters): array
    {
        $payouts = $this->scopedPayouts($brand->id, 'brand', $filters);
        $rows = [];

        foreach ($payouts as $payout) {
            if (! $payout->payment_intent_id) {
                continue;
            }

            try {
                $pi = $this->stripe->paymentIntents->retrieve($payout->payment_intent_id, [
                    'expand' => ['latest_charge.refunds'],
                ]);
            } catch (\Throwable $e) {
                Log::warning('stripe.txn.pi_fetch_failed', [
                    'payout_id' => $payout->id,
                    'pi_id' => $payout->payment_intent_id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $charge = is_object($pi->latest_charge ?? null) ? $pi->latest_charge : null;

            if ($charge !== null) {
                $rows[] = $this->normalizeBrandCharge($charge, $payout);

                $refunds = $charge->refunds->data ?? [];
                foreach ($refunds as $refund) {
                    $rows[] = $this->normalizeBrandRefund($refund, $charge, $payout);
                }
            }
        }

        return $this->filterByType($this->sortDesc($rows), $filters['type'] ?? 'all');
    }

    /**
     * @return array<int, array<string, mixed>> rows shaped for TransactionResource
     */
    public function forAffiliate(Professional $affiliate, array $filters): array
    {
        $payouts = $this->scopedPayouts($affiliate->id, 'affiliate', $filters);
        $rows = [];

        foreach ($payouts as $payout) {
            if (! $payout->charge_id) {
                continue;
            }

            try {
                $charge = $this->stripe->charges->retrieve($payout->charge_id, [
                    'expand' => ['transfer.reversals'],
                ]);
            } catch (\Throwable $e) {
                Log::warning('stripe.txn.charge_fetch_failed', [
                    'payout_id' => $payout->id,
                    'charge_id' => $payout->charge_id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $transfer = is_object($charge->transfer ?? null) ? $charge->transfer : null;

            if ($transfer !== null) {
                $rows[] = $this->normalizeAffiliateTransfer($transfer, $payout);

                $reversals = $transfer->reversals->data ?? [];
                foreach ($reversals as $reversal) {
                    $rows[] = $this->normalizeAffiliateReversal($reversal, $transfer, $payout);
                }
            }
        }

        return $this->filterByType($this->sortDesc($rows), $filters['type'] ?? 'all');
    }

    private function scopedPayouts(string $professionalId, string $role, array $filters)
    {
        $query = CommissionPayout::query()
            ->with([
                'brandProfessional:id,display_name,handle,stripe_connect_account_id',
                'affiliateProfessional:id,display_name,handle,stripe_connect_account_id',
            ])
            ->orderByDesc('created_at');

        if ($role === 'brand') {
            $query->where('brand_professional_id', $professionalId);
        } else {
            $query->where('affiliate_professional_id', $professionalId);
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            // Inclusive of the entire `date_to` day — the frontend sends YYYY-MM-DD, not a datetime.
            $query->where('created_at', '<=', $filters['date_to'].' 23:59:59');
        }

        $limit = (int) ($filters['limit'] ?? 25);

        return $query->limit($limit)->get();
    }

    private function normalizeBrandCharge(object $charge, CommissionPayout $payout): array
    {
        $brand = $payout->brandProfessional;
        $affiliate = $payout->affiliateProfessional;

        return [
            'id' => 'charge:'.$charge->id,
            'type' => 'charge',
            'amount_cents' => (int) ($charge->amount ?? 0),
            'currency_code' => strtoupper((string) ($charge->currency ?? 'aud')),
            'status' => (string) ($charge->status ?? 'unknown'),
            'description' => $this->stringOrNull($charge->description ?? null)
                ?? sprintf('Commission charge — %s (%d %s)',
                    $affiliate?->display_name ?? 'affiliate',
                    (int) $payout->ledger_entry_count,
                    $payout->ledger_entry_count == 1 ? 'order' : 'orders'),
            'occurred_at' => $this->isoFromUnix($charge->created ?? null),
            'payout_id' => $payout->id,
            'orders_count' => (int) $payout->ledger_entry_count,
            'brand' => $this->shapeParty($brand),
            'affiliate' => $this->shapeParty($affiliate),
            'stripe_dashboard_url' => "https://dashboard.stripe.com/payments/{$charge->payment_intent}",
            'raw_stripe_id' => (string) $charge->id,
        ];
    }

    private function normalizeBrandRefund(object $refund, object $charge, CommissionPayout $payout): array
    {
        $brand = $payout->brandProfessional;
        $affiliate = $payout->affiliateProfessional;

        return [
            'id' => 'refund:'.$refund->id,
            'type' => 'refund',
            // Negative — refunds reduce what the brand paid.
            'amount_cents' => -1 * (int) ($refund->amount ?? 0),
            'currency_code' => strtoupper((string) ($refund->currency ?? $charge->currency ?? 'aud')),
            'status' => (string) ($refund->status ?? 'unknown'),
            'description' => sprintf('Refund of commission — %s', $affiliate?->display_name ?? 'affiliate'),
            'occurred_at' => $this->isoFromUnix($refund->created ?? null),
            'payout_id' => $payout->id,
            'orders_count' => null,
            'brand' => $this->shapeParty($brand),
            'affiliate' => $this->shapeParty($affiliate),
            'stripe_dashboard_url' => "https://dashboard.stripe.com/payments/{$charge->payment_intent}",
            'raw_stripe_id' => (string) $refund->id,
        ];
    }

    private function normalizeAffiliateTransfer(object $transfer, CommissionPayout $payout): array
    {
        $brand = $payout->brandProfessional;
        $affiliate = $payout->affiliateProfessional;
        $destinationPaymentId = $this->stringOrNull($transfer->destination_payment ?? null);

        return [
            'id' => 'transfer:'.$transfer->id,
            'type' => 'transfer',
            'amount_cents' => (int) ($transfer->amount ?? 0),
            'currency_code' => strtoupper((string) ($transfer->currency ?? 'aud')),
            'status' => 'completed',
            // The enrichment we landed in the previous merge sets this to
            // "Partna commission from {brand} (payout {id}, N orders)"; fall back if absent.
            'description' => $this->stringOrNull($transfer->description ?? null)
                ?? sprintf('Commission from %s (%d %s)',
                    $brand?->display_name ?? 'brand',
                    (int) $payout->ledger_entry_count,
                    $payout->ledger_entry_count == 1 ? 'order' : 'orders'),
            'occurred_at' => $this->isoFromUnix($transfer->created ?? null),
            'payout_id' => $payout->id,
            'orders_count' => (int) $payout->ledger_entry_count,
            'brand' => $this->shapeParty($brand),
            'affiliate' => $this->shapeParty($affiliate),
            // The affiliate-side deep-link points at their own Express dashboard payment row,
            // not the platform transfer object (which their account can't see).
            'stripe_dashboard_url' => $destinationPaymentId
                ? "https://dashboard.stripe.com/connect/accounts/{$affiliate?->stripe_connect_account_id}/payments/{$destinationPaymentId}"
                : null,
            'raw_stripe_id' => $destinationPaymentId ?? (string) $transfer->id,
        ];
    }

    private function normalizeAffiliateReversal(object $reversal, object $transfer, CommissionPayout $payout): array
    {
        $brand = $payout->brandProfessional;
        $affiliate = $payout->affiliateProfessional;

        return [
            'id' => 'reversal:'.$reversal->id,
            'type' => 'reversal',
            // Negative — reversals claw back from the affiliate's balance.
            'amount_cents' => -1 * (int) ($reversal->amount ?? 0),
            'currency_code' => strtoupper((string) ($reversal->currency ?? $transfer->currency ?? 'aud')),
            'status' => 'completed',
            'description' => sprintf('Clawback to %s', $brand?->display_name ?? 'brand'),
            'occurred_at' => $this->isoFromUnix($reversal->created ?? null),
            'payout_id' => $payout->id,
            'orders_count' => null,
            'brand' => $this->shapeParty($brand),
            'affiliate' => $this->shapeParty($affiliate),
            'stripe_dashboard_url' => null,
            'raw_stripe_id' => (string) $reversal->id,
        ];
    }

    private function shapeParty(?Professional $pro): ?array
    {
        if (! $pro) {
            return null;
        }

        return [
            'id' => (string) $pro->id,
            'name' => $pro->display_name,
            'handle' => $pro->handle,
        ];
    }

    private function isoFromUnix(?int $unix): ?string
    {
        if (! $unix) {
            return null;
        }

        return \Carbon\CarbonImmutable::createFromTimestamp($unix)->toIso8601String();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function sortDesc(array $rows): array
    {
        usort($rows, fn ($a, $b) => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));

        return $rows;
    }

    private function filterByType(array $rows, string $type): array
    {
        if ($type === 'all' || $type === '') {
            return $rows;
        }

        return array_values(array_filter($rows, fn ($r) => $r['type'] === $type));
    }
}
