<?php

it('registers contact as a section_block_type', function () {
    expect(config('sidest.section_block_types'))->toContain('contact');
});

it('allows contact for influencer account type', function () {
    expect(config('sidest.account_type_defaults.influencer.allowed_sections'))
        ->toContain('contact');
});

it('allows contact for professional account type', function () {
    expect(config('sidest.account_type_defaults.professional.allowed_sections'))
        ->toContain('contact');
});

it('allows contact for brand account type', function () {
    expect(config('sidest.account_type_defaults.brand.allowed_sections'))
        ->toContain('contact');
});

it('does NOT auto-provision contact in default_sections', function () {
    // Contact is opt-in — pros add the block when they want it.
    foreach (['influencer', 'professional', 'brand'] as $type) {
        expect(config("sidest.account_type_defaults.{$type}.default_sections"))
            ->not->toContain('contact');
    }
});

it('exposes platform-default contact subject options', function () {
    $defaults = config('sidest.contact_subject_defaults');

    expect($defaults)
        ->toBeArray()
        ->toContain('General enquiry')
        ->toContain('Booking')
        ->toContain('Press')
        ->toContain('Collaboration')
        ->toContain('Other');
});
