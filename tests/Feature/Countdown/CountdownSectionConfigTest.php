<?php

it('registers countdown as a section_block_type', function () {
    expect(config('partna.section_block_types'))->toContain('countdown');
});

it('allows countdown for influencer account type', function () {
    expect(config('partna.account_type_defaults.influencer.allowed_sections'))
        ->toContain('countdown');
});

it('allows countdown for professional account type', function () {
    expect(config('partna.account_type_defaults.professional.allowed_sections'))
        ->toContain('countdown');
});

it('allows countdown for brand account type', function () {
    expect(config('partna.account_type_defaults.brand.allowed_sections'))
        ->toContain('countdown');
});

it('does NOT auto-provision countdown in default_sections', function () {
    // Countdown is opt-in — affiliates configure the timeline when they
    // want one, not an empty-by-default block sitting on the page.
    foreach (['influencer', 'professional', 'brand'] as $type) {
        expect(config("partna.account_type_defaults.{$type}.default_sections"))
            ->not->toContain('countdown');
    }
});
