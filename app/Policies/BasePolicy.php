<?php

namespace App\Policies;

use App\Models\Core\Professional\Professional;
use Illuminate\Auth\Access\Response;

// V2: Base for all auth Policies. Provides the shared pending_deletion read-only
// guard. Concrete Policies call denyIfPendingDeletion() as the first line of any
// ability that mutates state — this mirrors the EnforcePendingDeletionReadOnly
// HTTP middleware so background jobs and console commands get the same gate.
abstract class BasePolicy
{
    /**
     * Returns a 423 deny Response when the actor's account is pending deletion,
     * otherwise null. Caller convention: any write-capable ability returns
     * this result early when non-null.
     */
    protected function denyIfPendingDeletion(Professional $professional): ?Response
    {
        if (($professional->status ?? null) === 'pending_deletion') {
            return Response::denyWithStatus(423, 'Account is pending deletion.');
        }

        return null;
    }
}
