<?php

namespace App\Policies;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalDeletionAuditEntry;
use App\Models\Core\Professional\WalletCurrencySwitchAudit;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for records where the actor can only access their own data.
 *
 * Covers ProfessionalConfirmationPreference (read/write), WalletCurrencySwitchAudit
 * (read-only, append-only), and ProfessionalDeletionAuditEntry (read-only, append-only).
 * All three carry professional_id directly.
 *
 * Audit-log models (WalletCurrencySwitchAudit, ProfessionalDeletionAuditEntry) are
 * immutable — update/delete are blocked by the policy regardless of ownership.
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
        // Audit-log models are append-only — deny all mutations regardless of ownership.
        if ($resource instanceof WalletCurrencySwitchAudit || $resource instanceof ProfessionalDeletionAuditEntry) {
            return $this->denyAsNotFound();
        }

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
