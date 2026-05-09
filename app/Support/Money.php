<?php

namespace App\Support;

final class Money
{
    /**
     * Format an integer cent value as a human-readable currency string.
     *
     * Normalises the currency code (trim + uppercase) and falls back to AUD
     * when an empty code is provided, so callers are safe against blank DB values.
     */
    public static function format(int $cents, string $currencyCode): string
    {
        $currencyCode = strtoupper(trim($currencyCode));
        if ($currencyCode === '') {
            $currencyCode = 'AUD';
        }

        $prefix = match ($currencyCode) {
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            'AUD' => 'A$',
            default => $currencyCode.' ',
        };

        return $prefix.number_format($cents / 100, 2, '.', ',');
    }
}
