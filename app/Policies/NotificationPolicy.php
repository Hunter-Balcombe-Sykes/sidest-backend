<?php

namespace App\Policies;

use App\Models\Core\Notifications\Notification;
use App\Models\Core\Professional\Professional;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for notification records.
 *
 * Notification.professional_id is nullable: null = global broadcast (visible to
 * all professionals). Targeted notifications (non-null) are only visible to the
 * matching professional.
 *
 * NotificationEmailPreference, NotificationEmailPolicy, NotificationReceipt,
 * EmailSubscription all use standard direct professional_id ownership.
 */
class NotificationPolicy extends BasePolicy
{
    public function view(Professional $actor, Model $resource): bool|Response
    {
        // Global notifications (null professional_id) are visible to all.
        if ($resource instanceof Notification && $resource->professional_id === null) {
            return true;
        }

        if ((string) ($resource->professional_id ?? '') !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function update(Professional $actor, Model $resource): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ((string) ($resource->professional_id ?? '') !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function delete(Professional $actor, Model $resource): bool|Response
    {
        return $this->update($actor, $resource);
    }
}
