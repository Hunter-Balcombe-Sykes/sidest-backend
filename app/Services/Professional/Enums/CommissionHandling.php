<?php

namespace App\Services\Professional\Enums;

// How pending commissions are handled on disconnect.
// Keep: leave them in the ledger to follow normal payout/void lifecycle.
// Void: void them immediately with the disconnect reason. Staff-only.
enum CommissionHandling: string
{
    case Keep = 'keep';
    case Void = 'void';
}
