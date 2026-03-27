<?php

namespace App\Observers\Core;

use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Log;

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
