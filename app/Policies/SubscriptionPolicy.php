<?php

namespace App\Policies;

use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use Illuminate\Auth\Access\Response;

/**
 * V2: Authorization for Subscription records.
 * A professional can only view and modify their own subscription.
 * Pending-deletion actors may still view their subscription (so they can
 * see billing state before leaving), but cannot make changes.
 */
class SubscriptionPolicy extends BasePolicy
{
    public function view(Professional $actor, Subscription $subscription): bool|Response
    {
        if ((string) $subscription->professional_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function update(Professional $actor, Subscription $subscription): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ((string) $subscription->professional_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function delete(Professional $actor, Subscription $subscription): bool|Response
    {
        return $this->update($actor, $subscription);
    }
}
