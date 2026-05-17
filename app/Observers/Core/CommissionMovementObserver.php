<?php

namespace App\Observers\Core;

use App\Models\Commerce\CommissionMovement;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Notifications\NotificationPublisher;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

// V2: Core. Publishes commission earned/reversed notifications to affiliates when ledger entries are created or status changes.
class CommissionMovementObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(private readonly NotificationPublisher $publisher) {}

    public function created(CommissionMovement $entry): void
    {
        try {
            if ($entry->status !== 'approved') {
                return;
            }

            $this->notifyEarned($entry);
            $this->notifyBrandSale($entry);
        } catch (\Throwable $e) {
            Log::warning('CommissionMovement created notification failed', $this->logContext(__METHOD__, [
                'entry_id' => $entry->id,
                'brand_professional_id' => $entry->brand_professional_id,
                'affiliate_professional_id' => $entry->affiliate_professional_id,
                'message' => $e->getMessage(),
            ]));
        }
    }

    public function updated(CommissionMovement $entry): void
    {
        try {
            if (! $entry->isDirty('status')) {
                return;
            }

            if ($entry->status === 'approved' && $entry->getOriginal('status') !== 'approved') {
                $this->notifyEarned($entry);

                return;
            }

            if ($entry->status === 'reversed') {
                $this->notifyReversed($entry);

                return;
            }

            if ($entry->status === 'voided') {
                $this->notifyVoided($entry);
            }
        } catch (\Throwable $e) {
            Log::warning('CommissionMovement updated notification failed', $this->logContext(__METHOD__, [
                'entry_id' => $entry->id,
                'brand_professional_id' => $entry->brand_professional_id,
                'affiliate_professional_id' => $entry->affiliate_professional_id,
                'message' => $e->getMessage(),
            ]));
        }
    }

    private function notifyEarned(CommissionMovement $entry): void
    {
        $affiliateId = trim((string) ($entry->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        $amount = Money::format((int) ($entry->amount_cents ?? 0), (string) ($entry->currency_code ?? 'AUD'));

        $this->publisher->publish(
            professionalId: $affiliateId,
            frontendType: 'Success',
            category: 'commissions',
            title: 'Commission earned',
            body: "You earned {$amount} in commission.",
            dedupeKey: "commission.earned.{$entry->id}",
            ctaUrl: '/account/store?section=analytics',
            retentionConfigKey: 'commission',
        );
    }

    private function notifyReversed(CommissionMovement $entry): void
    {
        $affiliateId = trim((string) ($entry->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        $amount = Money::format((int) ($entry->amount_cents ?? 0), (string) ($entry->currency_code ?? 'AUD'));

        $this->publisher->publish(
            professionalId: $affiliateId,
            frontendType: 'Warning',
            category: 'commissions',
            title: 'Commission reversed',
            body: "A commission of {$amount} has been reversed.",
            dedupeKey: "commission.reversed.{$entry->id}",
            ctaUrl: '/account/store?section=analytics',
            retentionConfigKey: 'commission',
        );
    }

    private function notifyVoided(CommissionMovement $entry): void
    {
        $affiliateId = trim((string) ($entry->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        $amount = Money::format((int) ($entry->amount_cents ?? 0), (string) ($entry->currency_code ?? 'AUD'));

        $this->publisher->publish(
            professionalId: $affiliateId,
            frontendType: 'Warning',
            category: 'commissions',
            title: 'Commission forfeited',
            body: "A commission of {$amount} has been forfeited because your Stripe account was not connected in time.",
            dedupeKey: "commission.voided.{$entry->id}",
            ctaUrl: '/account/settings?section=stripe',
            retentionConfigKey: 'commission',
        );
    }

    // V2: Notifies the brand when an affiliate sale generates commission.
    private function notifyBrandSale(CommissionMovement $entry): void
    {
        $brandId = trim((string) ($entry->brand_professional_id ?? ''));
        if ($brandId === '') {
            return;
        }

        $amount = Money::format((int) ($entry->amount_cents ?? 0), (string) ($entry->currency_code ?? 'AUD'));
        $affiliateName = $entry->affiliateProfessional?->display_name ?? 'An affiliate';

        $this->publisher->publish(
            professionalId: $brandId,
            frontendType: 'Success',
            category: 'commissions',
            title: 'Affiliate sale',
            body: "{$affiliateName} generated a sale — {$amount} commission accrued.",
            dedupeKey: "commission.brand_sale.{$entry->id}",
            ctaUrl: '/account/store?section=analytics',
            retentionConfigKey: 'commission',
        );
    }
}
