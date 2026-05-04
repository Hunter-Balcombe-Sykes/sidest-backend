<?php

namespace App\Policies;

use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\Professional;
use Illuminate\Auth\Access\Response;

/**
 * V2: Authorization for AffiliateProductSelection records.
 *
 * Ownership is two-sided:
 *   - affiliate_professional_id: the affiliate who made the selection (full CRUD)
 *   - brand_professional_id: the brand whose product was selected (read-only)
 *
 * The affiliate.only route middleware already prevents brands from reaching
 * affiliate product endpoints — this policy provides the resource-level check
 * for coverage compliance and any direct Gate calls.
 */
class AffiliateProductPolicy extends BasePolicy
{
    /**
     * Both the affiliate who selected and the brand whose product was selected can view.
     */
    public function view(Professional $actor, AffiliateProductSelection $selection): bool|Response
    {
        $actorId = (string) $actor->id;

        if ($actorId === (string) $selection->affiliate_professional_id) {
            return true;
        }

        if ($actorId === (string) $selection->brand_professional_id) {
            return true;
        }

        return $this->denyAsNotFound();
    }

    /**
     * Only the affiliate can create a selection (for their own account).
     */
    public function create(Professional $actor, AffiliateProductSelection $skeleton): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        return (string) $skeleton->affiliate_professional_id === (string) $actor->id;
    }

    /**
     * Only the affiliate who owns the selection can update it.
     */
    public function update(Professional $actor, AffiliateProductSelection $selection): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ((string) $selection->affiliate_professional_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function delete(Professional $actor, AffiliateProductSelection $selection): bool|Response
    {
        return $this->update($actor, $selection);
    }
}
