<?php

it('registers newsletter as a section_block_type', function () {
    expect(config('sidest.section_block_types'))->toContain('newsletter');
});

it('allows newsletter for influencer account type', function () {
    expect(config('sidest.account_type_defaults.influencer.allowed_sections'))
        ->toContain('newsletter');
});

it('allows newsletter for professional account type', function () {
    expect(config('sidest.account_type_defaults.professional.allowed_sections'))
        ->toContain('newsletter');
});

it('allows newsletter for brand account type', function () {
    expect(config('sidest.account_type_defaults.brand.allowed_sections'))
        ->toContain('newsletter');
});

it('does NOT auto-provision newsletter in default_sections', function () {
    // Newsletter is opt-in — pros add the block when they want it, rather than
    // getting an empty-by-default section. All three account types should leave
    // newsletter out of their default provisioning list.
    foreach (['influencer', 'professional', 'brand'] as $type) {
        expect(config("sidest.account_type_defaults.{$type}.default_sections"))
            ->not->toContain('newsletter');
    }
});
