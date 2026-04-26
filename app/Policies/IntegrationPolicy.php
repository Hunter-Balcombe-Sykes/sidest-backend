<?php

namespace App\Policies;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Store\BrandAccessService;
use Illuminate\Auth\Access\Response;

// V2: Authorization for ProfessionalIntegration (Square / Fresha / Shopify
// OAuth credentials). Two abilities:
//
//   view   — actor owns the integration OR has brand-team manage capability
//            for the integration's owning professional.
//   manage — same as view, plus the actor must not be pending_deletion
//            (returns 423 via BasePolicy::denyIfPendingDeletion).
//
// We use a single 'manage' ability covering connect/disconnect/sync because
// BrandAccessService already buckets these into CAPABILITY_SHOPIFY_MANAGE.
// Split if a role ever needs "sync but not disconnect".
class IntegrationPolicy extends BasePolicy
{
    public function __construct(private readonly BrandAccessService $brandAccess) {}

    public function view(Professional $actor, ProfessionalIntegration $integration): bool
    {
        return $this->actorCanReachOwner($actor, $integration);
    }

    private function actorCanReachOwner(Professional $actor, ProfessionalIntegration $integration): bool
    {
        $ownerId = trim((string) ($integration->professional_id ?? ''));
        if ($ownerId === '') {
            return false;
        }

        if ((string) $actor->id === $ownerId) {
            return true;
        }

        return $this->brandAccess->canManageShopify($actor, $ownerId);
    }
}
