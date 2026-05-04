<?php

namespace App\Policies;

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\BrandPartnerLinkEvent;
use App\Models\Core\Professional\Professional;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for brand-affiliate link models.
 *
 * Covers three models mapped in AppServiceProvider:
 *   - BrandPartnerLink      — brand writes, both sides read
 *   - BrandPartnerLinkEvent — append-only audit log; both sides read, no writes
 *   - BrandAffiliateInvite  — brand writes; claimed_professional_id can read own invite
 *
 * Note: BrandAffiliateInvite uses `claimed_professional_id` rather than
 * `affiliate_professional_id` — resolveAffiliateId() handles the difference.
 */
class BrandPartnerLinkPolicy extends BasePolicy
{
    /**
     * Both the brand owner and the affiliate (or claimed professional) can view.
     */
    public function view(Professional $actor, Model $record): bool|Response
    {
        // Audit-log events are never write-able; view is handled the same as links.
        $brandId = (string) ($record->brand_professional_id ?? '');
        $actorId = (string) $actor->id;

        if ($brandId === '') {
            return $this->denyAsNotFound();
        }

        if ($actorId === $brandId) {
            return true;
        }

        $affiliateId = $this->resolveAffiliateId($record);
        if ($affiliateId !== '' && $actorId === $affiliateId) {
            return true;
        }

        return $this->denyAsNotFound();
    }

    /**
     * Only the brand owner can create a partner link.
     */
    public function create(Professional $actor, BrandPartnerLink $skeleton): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        $brandId = (string) ($skeleton->brand_professional_id ?? '');

        return $brandId !== '' && (string) $actor->id === $brandId;
    }

    /**
     * Only the brand owner can mutate. Audit-log events are always denied here
     * regardless of who asks — they are append-only at the DB level.
     */
    public function update(Professional $actor, Model $record): bool|Response
    {
        // Audit-log events are immutable — deny all writes without leaking existence.
        if ($record instanceof BrandPartnerLinkEvent) {
            return $this->denyAsNotFound();
        }

        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        $brandId = (string) ($record->brand_professional_id ?? '');
        $actorId = (string) $actor->id;

        if ($brandId === '') {
            return $this->denyAsNotFound();
        }

        if ($actorId === $brandId) {
            return true;
        }

        return $this->denyAsNotFound();
    }

    public function delete(Professional $actor, Model $record): bool|Response
    {
        return $this->update($actor, $record);
    }

    /**
     * Resolve the read-permitted "affiliate" side of the record.
     *
     * BrandPartnerLink and BrandPartnerLinkEvent use `affiliate_professional_id`.
     * BrandAffiliateInvite uses `claimed_professional_id` instead.
     */
    private function resolveAffiliateId(Model $record): string
    {
        return (string) ($record->affiliate_professional_id
            ?? $record->claimed_professional_id
            ?? '');
    }
}
