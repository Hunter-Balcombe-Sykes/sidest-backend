<?php

namespace App\Http\Controllers\Concerns;

// V2: Normalizes Shopify shop domains by stripping protocol, port, and trailing characters to a bare hostname.
trait NormalizesShopDomain
{
    private function normalizeShopDomain(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '://')) {
            $value = mb_strtolower(trim((string) parse_url($value, PHP_URL_HOST)));
        }

        $value = preg_replace('/:\d+$/', '', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B./");

        return $value;
    }
}
