<?php

namespace App\Services\Professional\Brand;

use App\Models\Core\Professional\BrandPartnerLinkEvent;
use App\Services\Professional\Enums\DisconnectActor;
use LogicException;

// Inserts rows into brand.brand_partner_link_events after validating the
// actor-specific invariants that cannot be expressed as a DB CHECK:
// - brand actor's actor_professional_id must equal brand_professional_id
// - affiliate actor's actor_professional_id must equal affiliate_professional_id
// - staff actor requires a non-null staff_user_id
class BrandPartnerLinkAuditor
{
    public function recordCreation(
        string $brandProfessionalId,
        string $affiliateProfessionalId,
        string $staffUserId,
        int $slotAtEvent,
        string $reason,
    ): BrandPartnerLinkEvent {
        if (trim($staffUserId) === '') {
            throw new LogicException('staff_user_id required for created event');
        }

        return BrandPartnerLinkEvent::query()->create([
            'brand_professional_id' => $brandProfessionalId,
            'affiliate_professional_id' => $affiliateProfessionalId,
            'event_type' => 'created',
            'actor_type' => DisconnectActor::Staff->value,
            'actor_professional_id' => null,
            'staff_user_id' => $staffUserId,
            'slot_at_event' => $slotAtEvent,
            'pending_commission_count' => null,
            'pending_commission_cents' => null,
            'commissions_voided_count' => 0,
            'commissions_voided_cents' => 0,
            'reason' => $reason,
        ]);
    }

    public function recordRemoval(
        string $brandProfessionalId,
        string $affiliateProfessionalId,
        DisconnectActor $actor,
        ?string $actorProfessionalId,
        ?string $staffUserId,
        int $slotAtEvent,
        int $pendingCount,
        int $pendingCents,
        int $voidedCount,
        int $voidedCents,
        ?string $reason,
    ): BrandPartnerLinkEvent {
        $this->assertActorInvariants($brandProfessionalId, $affiliateProfessionalId, $actor, $actorProfessionalId, $staffUserId);

        return BrandPartnerLinkEvent::query()->create([
            'brand_professional_id' => $brandProfessionalId,
            'affiliate_professional_id' => $affiliateProfessionalId,
            'event_type' => 'removed',
            'actor_type' => $actor->value,
            'actor_professional_id' => $actorProfessionalId,
            'staff_user_id' => $staffUserId,
            'slot_at_event' => $slotAtEvent,
            'pending_commission_count' => $pendingCount,
            'pending_commission_cents' => $pendingCents,
            'commissions_voided_count' => $voidedCount,
            'commissions_voided_cents' => $voidedCents,
            'reason' => $reason,
        ]);
    }

    /** Recorded by the async void job when it finishes processing overflow. */
    public function recordAsyncVoidCompletion(
        string $brandProfessionalId,
        string $affiliateProfessionalId,
        int $voidedCount,
        int $voidedCents,
        string $reason,
    ): BrandPartnerLinkEvent {
        return BrandPartnerLinkEvent::query()->create([
            'brand_professional_id' => $brandProfessionalId,
            'affiliate_professional_id' => $affiliateProfessionalId,
            'event_type' => 'commissions_voided_async',
            'actor_type' => DisconnectActor::Staff->value,
            'actor_professional_id' => null,
            'staff_user_id' => null, // follow-up row; original 'removed' row carries the staff id
            'slot_at_event' => null,
            'pending_commission_count' => null,
            'pending_commission_cents' => null,
            'commissions_voided_count' => $voidedCount,
            'commissions_voided_cents' => $voidedCents,
            'reason' => $reason,
        ]);
    }

    private function assertActorInvariants(
        string $brandId,
        string $affiliateId,
        DisconnectActor $actor,
        ?string $actorProfessionalId,
        ?string $staffUserId,
    ): void {
        match ($actor) {
            DisconnectActor::Staff => (function () use ($staffUserId): void {
                if ($staffUserId === null || trim($staffUserId) === '') {
                    throw new LogicException('staff_user_id required for staff actor');
                }
            })(),
            DisconnectActor::Brand => (function () use ($brandId, $actorProfessionalId): void {
                if ($actorProfessionalId !== $brandId) {
                    throw new LogicException('actor_professional_id must match brand_professional_id for brand actor');
                }
            })(),
            DisconnectActor::Affiliate => (function () use ($affiliateId, $actorProfessionalId): void {
                if ($actorProfessionalId !== $affiliateId) {
                    throw new LogicException('actor_professional_id must match affiliate_professional_id for affiliate actor');
                }
            })(),
        };
    }
}
