<?php

namespace App\Services\FeatureFlags;

use InvalidArgumentException;

final class OverrideScope
{
    private function __construct(
        public readonly ?string $professionalId,
        public readonly ?string $brandId,
    ) {
        if ($professionalId === null && $brandId === null) {
            throw new InvalidArgumentException('OverrideScope requires either professionalId or brandId');
        }
        if ($professionalId === '' || $brandId === '') {
            throw new InvalidArgumentException('OverrideScope id must not be empty string');
        }
    }

    public static function forProfessional(string $professionalId): self
    {
        return new self(professionalId: $professionalId, brandId: null);
    }

    public static function forBrand(string $brandId): self
    {
        return new self(professionalId: null, brandId: $brandId);
    }
}
