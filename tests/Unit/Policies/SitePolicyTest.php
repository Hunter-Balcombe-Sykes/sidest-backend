<?php

use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Policies\SitePolicy;

beforeEach(function () {
    $this->policy = new SitePolicy;
});

// ---------------------------------------------------------------------------
// Site itself
// ---------------------------------------------------------------------------

describe('Site', function () {
    // Site.professional_id is not in $fillable — use forceFill in tests
    // so the attribute lands in the raw attributes array.
    it('allows view when the actor owns the site', function () {
        $actor = (new Professional)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['professional_id' => 'pro-actor']);

        expect($this->policy->view($actor, $site))->toBeTrue();
    });

    it('denies view with 404 when the actor does not own the site', function () {
        $actor = (new Professional)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['professional_id' => 'pro-other']);

        $result = $this->policy->view($actor, $site);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(404);
    });

    it('allows update when the actor owns the site and is active', function () {
        $actor = (new Professional)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['professional_id' => 'pro-actor']);

        expect($this->policy->update($actor, $site))->toBeTrue();
    });

    it('denies update with 404 when the actor does not own the site', function () {
        $actor = (new Professional)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['professional_id' => 'pro-other']);

        $result = $this->policy->update($actor, $site);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(404);
    });

    it('denies update with 423 when the actor is pending deletion', function () {
        $actor = (new Professional)->forceFill(['id' => 'pro-actor', 'status' => 'pending_deletion']);
        $site = (new Site)->forceFill(['professional_id' => 'pro-actor']);

        $result = $this->policy->update($actor, $site);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(423);
        expect($result->message())->toBe('Account is pending deletion.');
    });

    it('denies create with 423 when the actor is pending deletion', function () {
        $actor = (new Professional)->forceFill(['id' => 'pro-actor', 'status' => 'pending_deletion']);
        $skeleton = (new Site)->forceFill(['professional_id' => 'pro-actor']);

        $result = $this->policy->create($actor, $skeleton);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(423);
        expect($result->message())->toBe('Account is pending deletion.');
    });
});

// ---------------------------------------------------------------------------
// SiteMedia — ownership resolved via preloaded site relation
// ---------------------------------------------------------------------------

describe('SiteMedia', function () {
    // Site.professional_id not in $fillable — must forceFill so it lands in getAttributes().
    // The policy verifies that $media->site_id matches the preloaded site's id, so both
    // must be set consistently.
    it('allows view when the site relation is owned by the actor', function () {
        $actor = (new Professional)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['id' => 'site-1', 'professional_id' => 'pro-actor']);
        $media = new SiteMedia(['site_id' => 'site-1']);
        $media->setRelation('site', $site);

        expect($this->policy->view($actor, $media))->toBeTrue();
    });

    it('denies view with 404 when the site relation belongs to a different owner', function () {
        $actor = (new Professional)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['id' => 'site-2', 'professional_id' => 'pro-other']);
        $media = new SiteMedia(['site_id' => 'site-2']);
        $media->setRelation('site', $site);

        $result = $this->policy->view($actor, $media);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(404);
    });

    it('allows delete when the actor owns the site relation', function () {
        $actor = (new Professional)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['id' => 'site-1', 'professional_id' => 'pro-actor']);
        $media = new SiteMedia(['site_id' => 'site-1']);
        $media->setRelation('site', $site);

        expect($this->policy->delete($actor, $media))->toBeTrue();
    });

    it('denies delete with 423 when actor is pending deletion', function () {
        $actor = (new Professional)->forceFill(['id' => 'pro-actor', 'status' => 'pending_deletion']);
        $site = (new Site)->forceFill(['id' => 'site-1', 'professional_id' => 'pro-actor']);
        $media = new SiteMedia(['site_id' => 'site-1']);
        $media->setRelation('site', $site);

        $result = $this->policy->delete($actor, $media);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(423);
    });
});

// ---------------------------------------------------------------------------
// Block — denormalized professional_id
// ---------------------------------------------------------------------------

describe('Block', function () {
    it('allows view when the actor owns the block', function () {
        $actor = (new Professional)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $block = new Block(['professional_id' => 'pro-actor']);

        expect($this->policy->view($actor, $block))->toBeTrue();
    });

    it('denies view with 404 when the actor does not own the block', function () {
        $actor = (new Professional)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $block = new Block(['professional_id' => 'pro-other']);

        $result = $this->policy->view($actor, $block);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(404);
    });
});
