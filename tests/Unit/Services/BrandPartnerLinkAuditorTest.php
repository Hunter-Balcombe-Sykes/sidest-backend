<?php

use App\Models\Core\Professional\BrandPartnerLinkEvent;
use App\Services\Professional\BrandPartnerLinkAuditor;
use App\Services\Professional\Enums\DisconnectActor;
use Illuminate\Support\Str;

it('rejects brand actor with mismatched actor_professional_id', function () {
    $auditor = new BrandPartnerLinkAuditor();
    $brand = (string) Str::uuid();
    $affiliate = (string) Str::uuid();
    $wrong = (string) Str::uuid();

    expect(fn () => $auditor->recordRemoval(
        brandProfessionalId: $brand,
        affiliateProfessionalId: $affiliate,
        actor: DisconnectActor::Brand,
        actorProfessionalId: $wrong,
        staffUserId: null,
        slotAtEvent: 0,
        pendingCount: 0,
        pendingCents: 0,
        voidedCount: 0,
        voidedCents: 0,
        reason: null,
    ))->toThrow(LogicException::class, 'actor_professional_id must match brand');
});

it('rejects affiliate actor with mismatched actor_professional_id', function () {
    $auditor = new BrandPartnerLinkAuditor();
    $brand = (string) Str::uuid();
    $affiliate = (string) Str::uuid();
    $wrong = (string) Str::uuid();

    expect(fn () => $auditor->recordRemoval(
        brandProfessionalId: $brand,
        affiliateProfessionalId: $affiliate,
        actor: DisconnectActor::Affiliate,
        actorProfessionalId: $wrong,
        staffUserId: null,
        slotAtEvent: 0,
        pendingCount: 0,
        pendingCents: 0,
        voidedCount: 0,
        voidedCents: 0,
        reason: null,
    ))->toThrow(LogicException::class, 'actor_professional_id must match affiliate');
});

it('rejects staff actor with null staff_user_id', function () {
    $auditor = new BrandPartnerLinkAuditor();
    $brand = (string) Str::uuid();
    $affiliate = (string) Str::uuid();

    expect(fn () => $auditor->recordRemoval(
        brandProfessionalId: $brand,
        affiliateProfessionalId: $affiliate,
        actor: DisconnectActor::Staff,
        actorProfessionalId: null,
        staffUserId: null,
        slotAtEvent: 0,
        pendingCount: 0,
        pendingCents: 0,
        voidedCount: 0,
        voidedCents: 0,
        reason: 'x',
    ))->toThrow(LogicException::class, 'staff_user_id required');
});
