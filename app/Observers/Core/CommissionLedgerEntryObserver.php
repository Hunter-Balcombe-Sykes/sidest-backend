<?php

namespace App\Observers\Core;

use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Log;

// V2: Core. Publishes commission earned/reversed notifications to affiliates when ledger entries are created or status changes.
class CommissionLedgerEntryObserver
{
    public bool $afterCommit = true;

    public function __construct(private readonly NotificationPublisher $publisher) {}

    public function created(CommissionLedgerEntry $entry): void
    {
        try {
            if ($entry->status !== 'approved') {
                return;
            }

            $this->notifyEarned($entry);
            $this->notifyBrandSale($entry);
        } catch (\Throwable $e) {
            Log::warning('CommissionLedgerEntry created notification failed', [
                'entry_id' => $entry->id,
                'message'  => $e->getMessage(),
            ]);
        }
    }

    public function updated(CommissionLedgerEntry $entry): void
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
            }
        } catch (\Throwable $e) {
            Log::warning('CommissionLedgerEntry updated notification failed', [
                'entry_id' => $entry->id,
                'message'  => $e->getMessage(),
            ]);
        }
    }

    private function notifyEarned(CommissionLedgerEntry $entry): void
    {
        $affiliateId = trim((string) ($entry->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        $amount = $this->formatMoney((int) ($entry->amount_cents ?? 0), (string) ($entry->currency_code ?? 'AUD'));

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

    private function notifyReversed(CommissionLedgerEntry $entry): void
    {
        $affiliateId = trim((string) ($entry->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        $amount = $this->formatMoney((int) ($entry->amount_cents ?? 0), (string) ($entry->currency_code ?? 'AUD'));

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

    // V2: Notifies the brand when an affiliate sale generates commission.
    private function notifyBrandSale(CommissionLedgerEntry $entry): void
    {
        $brandId = trim((string) ($entry->brand_professional_id ?? ''));
        if ($brandId === '') {
            return;
        }

        $amount = $this->formatMoney((int) ($entry->amount_cents ?? 0), (string) ($entry->currency_code ?? 'AUD'));
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

    private function formatMoney(int $cents, string $currencyCode): string
    {
        $prefix = match (strtoupper($currencyCode)) {
            'USD'   => '$',
            'GBP'   => '£',
            'EUR'   => '€',
            'AUD'   => 'A$',
            default => strtoupper($currencyCode) . ' ',
        };

        return $prefix . number_format($cents / 100, 2, '.', ',');
    }
}
