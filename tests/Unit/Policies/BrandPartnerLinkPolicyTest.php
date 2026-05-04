<?php

use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\BrandPartnerLinkEvent;
use App\Models\Core\Professional\Professional;
use App\Policies\BrandPartnerLinkPolicy;
use Illuminate\Auth\Access\Response;

beforeEach(function () {
    $this->policy = new BrandPartnerLinkPolicy;
    $this->brand = (new Professional)->forceFill(['id' => 'brand-actor', 'status' => 'active', 'professional_type' => 'brand']);
    $this->affiliate = (new Professional)->forceFill(['id' => 'affiliate-actor', 'status' => 'active', 'professional_type' => 'professional']);
});

// ---------------------------------------------------------------------------
// BrandPartnerLink — view
// ---------------------------------------------------------------------------

describe('BrandPartnerLink view', function () {
    it('allows brand owner to view', function () {
        $actor = (new Professional)->forceFill(['id' => 'brand-1', 'status' => 'active', 'professional_type' => 'brand']);
        $link = (new BrandPartnerLink)->forceFill(['brand_professional_id' => 'brand-1', 'affiliate_professional_id' => 'aff-1']);

        expect($this->policy->view($actor, $link))->toBeTrue();
    });

    it('allows affiliate to view', function () {
        $actor = (new Professional)->forceFill(['id' => 'aff-1', 'status' => 'active', 'professional_type' => 'professional']);
        $link = (new BrandPartnerLink)->forceFill(['brand_professional_id' => 'brand-1', 'affiliate_professional_id' => 'aff-1']);

        expect($this->policy->view($actor, $link))->toBeTrue();
    });

    it('denies third party with 404', function () {
        $actor = (new Professional)->forceFill(['id' => 'nobody', 'status' => 'active', 'professional_type' => 'professional']);
        $link = (new BrandPartnerLink)->forceFill(['brand_professional_id' => 'brand-1', 'affiliate_professional_id' => 'aff-1']);

        $result = $this->policy->view($actor, $link);

        expect($result)->toBeInstanceOf(Response::class);
        expect($result->denied())->toBeTrue();
        expect($result->status())->toBe(404);
    });
});

// ---------------------------------------------------------------------------
// BrandPartnerLink — update
// ---------------------------------------------------------------------------

describe('BrandPartnerLink update', function () {
    it('allows brand owner to update', function () {
        $actor = (new Professional)->forceFill(['id' => 'brand-1', 'status' => 'active', 'professional_type' => 'brand']);
        $link = (new BrandPartnerLink)->forceFill(['brand_professional_id' => 'brand-1', 'affiliate_professional_id' => 'aff-1']);

        expect($this->policy->update($actor, $link))->toBeTrue();
    });

    it('denies affiliate with 404', function () {
        $actor = (new Professional)->forceFill(['id' => 'aff-1', 'status' => 'active', 'professional_type' => 'professional']);
        $link = (new BrandPartnerLink)->forceFill(['brand_professional_id' => 'brand-1', 'affiliate_professional_id' => 'aff-1']);

        $result = $this->policy->update($actor, $link);

        expect($result)->toBeInstanceOf(Response::class);
        expect($result->denied())->toBeTrue();
        expect($result->status())->toBe(404);
    });

    it('denies third party with 404', function () {
        $actor = (new Professional)->forceFill(['id' => 'nobody', 'status' => 'active', 'professional_type' => 'professional']);
        $link = (new BrandPartnerLink)->forceFill(['brand_professional_id' => 'brand-1', 'affiliate_professional_id' => 'aff-1']);

        $result = $this->policy->update($actor, $link);

        expect($result)->toBeInstanceOf(Response::class);
        expect($result->denied())->toBeTrue();
        expect($result->status())->toBe(404);
    });

    it('blocks update with 423 when brand is pending_deletion', function () {
        $actor = (new Professional)->forceFill(['id' => 'brand-1', 'status' => 'pending_deletion', 'professional_type' => 'brand']);
        $link = (new BrandPartnerLink)->forceFill(['brand_professional_id' => 'brand-1', 'affiliate_professional_id' => 'aff-1']);

        $result = $this->policy->update($actor, $link);

        expect($result)->toBeInstanceOf(Response::class);
        expect($result->status())->toBe(423);
        expect($result->message())->toBe('Account is pending deletion.');
    });
});

// ---------------------------------------------------------------------------
// BrandPartnerLink — create
// ---------------------------------------------------------------------------

describe('BrandPartnerLink create', function () {
    it('allows create when the actor is the brand owner', function () {
        $link = (new BrandPartnerLink)->forceFill(['brand_professional_id' => 'brand-actor']);
        expect($this->policy->create($this->brand, $link))->toBeTrue();
    });

    it('blocks create with 423 when the brand is pending deletion', function () {
        $this->brand->status = 'pending_deletion';
        $link = (new BrandPartnerLink)->forceFill(['brand_professional_id' => 'brand-actor']);
        $result = $this->policy->create($this->brand, $link);
        expect($result->status())->toBe(423);
    });

    it('denies create when the actor is not the brand', function () {
        $link = (new BrandPartnerLink)->forceFill(['brand_professional_id' => 'brand-other']);
        expect($this->policy->create($this->brand, $link))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// BrandPartnerLinkEvent — view (both sides can read; no writes)
// ---------------------------------------------------------------------------

describe('BrandPartnerLinkEvent view', function () {
    it('allows brand owner to view', function () {
        $actor = (new Professional)->forceFill(['id' => 'brand-1', 'status' => 'active', 'professional_type' => 'brand']);
        $event = (new BrandPartnerLinkEvent)->forceFill(['brand_professional_id' => 'brand-1', 'affiliate_professional_id' => 'aff-1']);

        expect($this->policy->view($actor, $event))->toBeTrue();
    });

    it('allows affiliate to view', function () {
        $actor = (new Professional)->forceFill(['id' => 'aff-1', 'status' => 'active', 'professional_type' => 'professional']);
        $event = (new BrandPartnerLinkEvent)->forceFill(['brand_professional_id' => 'brand-1', 'affiliate_professional_id' => 'aff-1']);

        expect($this->policy->view($actor, $event))->toBeTrue();
    });
});

describe('BrandPartnerLinkEvent update', function () {
    it('always denies update with 404 (audit log is immutable)', function () {
        $actor = (new Professional)->forceFill(['id' => 'brand-1', 'status' => 'active', 'professional_type' => 'brand']);
        $event = (new BrandPartnerLinkEvent)->forceFill(['brand_professional_id' => 'brand-1', 'affiliate_professional_id' => 'aff-1']);

        $result = $this->policy->update($actor, $event);

        expect($result)->toBeInstanceOf(Response::class);
        expect($result->denied())->toBeTrue();
        expect($result->status())->toBe(404);
    });
});

// ---------------------------------------------------------------------------
// BrandAffiliateInvite — view
// ---------------------------------------------------------------------------

describe('BrandAffiliateInvite view', function () {
    it('allows brand owner to view', function () {
        $actor = (new Professional)->forceFill(['id' => 'brand-1', 'status' => 'active', 'professional_type' => 'brand']);
        $invite = (new BrandAffiliateInvite)->forceFill(['brand_professional_id' => 'brand-1', 'claimed_professional_id' => null]);

        expect($this->policy->view($actor, $invite))->toBeTrue();
    });

    it('allows claimed_professional_id to view their own invite', function () {
        $actor = (new Professional)->forceFill(['id' => 'aff-1', 'status' => 'active', 'professional_type' => 'professional']);
        $invite = (new BrandAffiliateInvite)->forceFill(['brand_professional_id' => 'brand-1', 'claimed_professional_id' => 'aff-1']);

        expect($this->policy->view($actor, $invite))->toBeTrue();
    });

    it('denies unclaimed third party with 404', function () {
        $actor = (new Professional)->forceFill(['id' => 'nobody', 'status' => 'active', 'professional_type' => 'professional']);
        $invite = (new BrandAffiliateInvite)->forceFill(['brand_professional_id' => 'brand-1', 'claimed_professional_id' => 'aff-1']);

        $result = $this->policy->view($actor, $invite);

        expect($result)->toBeInstanceOf(Response::class);
        expect($result->denied())->toBeTrue();
        expect($result->status())->toBe(404);
    });
});

// ---------------------------------------------------------------------------
// BrandAffiliateInvite — update
// ---------------------------------------------------------------------------

describe('BrandAffiliateInvite update', function () {
    it('allows brand owner to update', function () {
        $actor = (new Professional)->forceFill(['id' => 'brand-1', 'status' => 'active', 'professional_type' => 'brand']);
        $invite = (new BrandAffiliateInvite)->forceFill(['brand_professional_id' => 'brand-1', 'claimed_professional_id' => 'aff-1']);

        expect($this->policy->update($actor, $invite))->toBeTrue();
    });

    it('denies claimed affiliate from updating with 404', function () {
        $actor = (new Professional)->forceFill(['id' => 'aff-1', 'status' => 'active', 'professional_type' => 'professional']);
        $invite = (new BrandAffiliateInvite)->forceFill(['brand_professional_id' => 'brand-1', 'claimed_professional_id' => 'aff-1']);

        $result = $this->policy->update($actor, $invite);

        expect($result)->toBeInstanceOf(Response::class);
        expect($result->denied())->toBeTrue();
        expect($result->status())->toBe(404);
    });

    it('blocks a pending-deletion brand from updating an invite with 423', function () {
        $this->brand->status = 'pending_deletion';
        $invite = (new BrandAffiliateInvite)->forceFill([
            'brand_professional_id' => 'brand-actor',
            'claimed_professional_id' => 'affiliate-actor',
        ]);
        $result = $this->policy->update($this->brand, $invite);
        expect($result->status())->toBe(423);
    });
});
