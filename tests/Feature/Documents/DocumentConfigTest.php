<?php

it('registers documents as a section_block_type', function () {
    expect(config('sidest.section_block_types'))->toContain('documents');
});

it('registers documents pool with max 1', function () {
    expect(config('sidest.image_pools.documents'))->toMatchArray(['max' => 1]);
});

it('allows documents for influencer (and therefore professional via inheritance)', function () {
    expect(config('sidest.account_type_defaults.influencer.allowed_sections'))
        ->toContain('documents');
});

it('allows documents for professional account type', function () {
    expect(config('sidest.account_type_defaults.professional.allowed_sections'))
        ->toContain('documents');
});

it('does NOT allow documents for brand accounts', function () {
    expect(config('sidest.account_type_defaults.brand.allowed_sections'))
        ->not->toContain('documents');
});
