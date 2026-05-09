<?php

namespace App\Policies;

use App\Models\Commerce\WalletMovement;
use App\Models\Core\Professional\Professional;

/**
 * Tenant-scoped read gate for wallet_movements.
 *
 * Professionals may only view their own ledger rows. Writes never go through
 * this policy — they happen via app_backend (BYPASSRLS) or staff tooling.
 */
class WalletMovementPolicy extends BasePolicy
{
    public function view(Professional $actor, WalletMovement $movement): bool
    {
        return (string) $actor->id === (string) $movement->professional_id;
    }
}
