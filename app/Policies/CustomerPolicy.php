<?php

namespace App\Policies;

use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;
use Illuminate\Auth\Access\Response;

/**
 * V2: Authorization for Customer records owned by a Professional.
 *
 * Denials on route-bound resources return 404 to avoid leaking existence to non-owners.
 */
class CustomerPolicy extends BasePolicy
{
    public function view(Professional $actor, Customer $customer): bool|Response
    {
        if ((string) $customer->professional_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function create(Professional $actor, Customer $skeleton): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        return (string) $skeleton->professional_id === (string) $actor->id;
    }

    public function update(Professional $actor, Customer $customer): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ((string) $customer->professional_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function delete(Professional $actor, Customer $customer): bool|Response
    {
        return $this->update($actor, $customer);
    }
}
