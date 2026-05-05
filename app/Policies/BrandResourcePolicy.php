<?php

namespace App\Policies;

use App\Models\Core\Professional\Professional;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for brand-owned resource records.
 * Covers BrandStoreSettings (professional_id), BrandProfile (professional_id),
 * and BrandTeamMembership (brand_professional_id).
 *
 * Brand-account eligibility (isBrand) is enforced by the brand.only
 * route middleware — this policy only handles resource ownership.
 */
class BrandResourcePolicy extends BasePolicy
{
    public function view(Professional $actor, Model $resource): bool|Response
    {
        if ($this->resolveOwnerId($resource) !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function create(Professional $actor, Model $skeleton): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        return $this->resolveOwnerId($skeleton) === (string) $actor->id;
    }

    public function update(Professional $actor, Model $resource): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ($this->resolveOwnerId($resource) !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function delete(Professional $actor, Model $resource): bool|Response
    {
        return $this->update($actor, $resource);
    }

    /**
     * BrandStoreSettings and BrandProfile use professional_id;
     * BrandTeamMembership uses brand_professional_id (the owning brand).
     */
    private function resolveOwnerId(Model $resource): string
    {
        if (isset($resource->brand_professional_id)) {
            return (string) $resource->brand_professional_id;
        }

        return (string) ($resource->professional_id ?? '');
    }
}
