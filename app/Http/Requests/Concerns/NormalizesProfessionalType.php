<?php

namespace App\Http\Requests\Concerns;

trait NormalizesProfessionalType
{
    protected function normalizeProfessionalTypeInput(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        $compact = preg_replace('/[^a-z]+/u', '', $normalized) ?? $normalized;

        return match ($compact) {
            'proffesional',
            'professional' => 'professional',
            'influencer' => 'influencer',
            'brand' => 'brand',
            default => $normalized,
        };
    }
}
