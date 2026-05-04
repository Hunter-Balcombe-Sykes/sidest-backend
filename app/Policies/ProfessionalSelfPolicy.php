<?php

namespace App\Policies;

use App\Models\Core\Professional\Professional;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for records where the actor can only access their own data.
 *
 * Covers ProfessionalConfirmationPreference (read/write), WalletCurrencySwitchAudit
 * (read-only), and ProfessionalDeletionAuditEntry (read-only). All three carry
 * professional_id directly.
 *
 * In Phase 4, Professional itself will be added here for account/profile endpoints.
 */
class ProfessionalSelfPolicy extends BasePolicy
{
    public function view(Professional $actor, Model $resource): bool|Response
    {
        if ((string) $resource->professional_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function update(Professional $actor, Model $resource): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ((string) $resource->professional_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function delete(Professional $actor, Model $resource): bool|Response
    {
        return $this->update($actor, $resource);
    }
}
