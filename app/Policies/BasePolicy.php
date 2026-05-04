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
        if ($professional->isPendingDeletion()) {
            return Response::denyWithStatus(423, 'Account is pending deletion.');
        }

        return null;
    }

    /**
     * Deny with a 404 to avoid leaking resource existence to non-owners.
     * Use in policy methods that gate route-bound resources — an actor reaching
     * this point has already submitted a valid UUID, and we don't want to
     * confirm or deny it exists if they don't have access.
     */
    protected function denyAsNotFound(): Response
    {
        return Response::denyAsNotFound('Not found.');
    }
}
