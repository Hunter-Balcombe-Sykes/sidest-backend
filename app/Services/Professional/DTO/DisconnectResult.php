<?php

namespace App\Services\Professional\DTO;

// Summary of side-effects from a disconnect, shaped for HTTP responses.
final class DisconnectResult
{
    public function __construct(
        public readonly bool $disconnected,
        public readonly int $voidedCommissionCount,
        public readonly int $voidedCommissionCents,
        public readonly int $selectionsRemoved,
        public readonly int $pendingCommissionCount = 0,
        public readonly int $pendingCommissionCents = 0,
        public readonly bool $voidedAsync = false,
        public readonly bool $staleSettingsCleaned = false,
    ) {}
}
