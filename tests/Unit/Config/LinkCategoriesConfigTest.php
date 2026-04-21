<?php

uses(Tests\TestCase::class)->in(__FILE__);

it('exposes the 6 link categories in config', function () {
    $categories = config('sidest.link_categories');

    expect($categories)->toBe(['social', 'booking', 'education', 'content', 'events', 'other']);
});

it('includes category in the link_block_settings_keys allowlist', function () {
    expect(config('sidest.link_block_settings_keys'))->toContain('category');
});
