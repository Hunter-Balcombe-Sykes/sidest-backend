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

it('exposes POOL_DOCUMENTS and MEDIA_TYPE_DOCUMENT constants', function () {
    expect(\App\Models\Core\Site\SiteMedia::POOL_DOCUMENTS)->toBe('documents');
    expect(\App\Models\Core\Site\SiteMedia::MEDIA_TYPE_DOCUMENT)->toBe('document');
});

it('SiteMedia accepts original_filename via mass assignment', function () {
    $media = new \App\Models\Core\Site\SiteMedia([
        'site_id' => (string) \Illuminate\Support\Str::uuid(),
        'pool' => 'documents',
        'media_type' => 'document',
        'path' => 'documents/foo/bar/original.pdf',
        'alt_text' => 'Spring Schedule',
        'caption' => 'Updated monthly',
        'original_mime' => 'application/pdf',
        'original_size_bytes' => 123456,
        'original_filename' => 'schedule-spring-2026.pdf',
        'processing_state' => 'ready',
    ]);

    expect($media->original_filename)->toBe('schedule-spring-2026.pdf');
    expect($media->pool)->toBe('documents');
    expect($media->media_type)->toBe('document');
});
