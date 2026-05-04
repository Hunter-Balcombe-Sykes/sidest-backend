<?php

namespace App\Policies;

use App\Models\Core\Professional\Professional;
use App\Models\Retail\BrandStoreSettings;
use Illuminate\Auth\Access\Response;

/**
 * V2: Authorization for brand-owned resource records. Currently covers BrandStoreSettings;
 * will expand to BrandProfile and BrandTeamMembership in Phase 4.
 * Brand-account eligibility (isBrandProfessional) is enforced by the brand.only route
 * middleware — this policy only handles resource ownership.
 */
class BrandResourcePolicy extends BasePolicy
{
    public function view(Professional $actor, BrandStoreSettings $settings): bool|Response
    {
        if ((string) $settings->professional_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function create(Professional $actor, BrandStoreSettings $skeleton): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        return (string) $skeleton->professional_id === (string) $actor->id;
    }

    public function update(Professional $actor, BrandStoreSettings $settings): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ((string) $settings->professional_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function delete(Professional $actor, BrandStoreSettings $settings): bool|Response
    {
        return $this->update($actor, $settings);
    }
}
