<?php

uses(Tests\TestCase::class)->in(__FILE__);

it('exposes the 6 link categories in config', function () {
    $categories = config('sidest.link_categories');

    expect($categories)->toBe(['social', 'booking', 'education', 'content', 'events', 'other']);
});

it('includes category in the link_block_settings_keys allowlist', function () {
    expect(config('sidest.link_block_settings_keys'))->toContain('category');
});

it('includes the 16 new platform icon keys in the allowlist', function () {
    $keys = config('sidest.link_block_icon_keys');

    foreach ([
        'fresha', 'booksy', 'timely', 'calendly', 'square',
        'stan', 'skool', 'kajabi', 'circle',
        'eventbrite', 'humanitix', 'luma', 'partiful',
        'apple_podcasts', 'substack', 'bandcamp',
    ] as $expected) {
        expect($keys)->toContain($expected);
    }
});

it('each existing social platform has default_category=social and handle_location=path', function () {
    foreach (['instagram', 'facebook', 'linkedin', 'youtube', 'tiktok', 'x', 'spotify', 'soundcloud'] as $key) {
        $config = config("sidest.social_platforms.{$key}");
        expect($config['default_category'])->toBe('social', "platform {$key} missing default_category=social");
        expect($config['handle_location'])->toBe('path', "platform {$key} missing handle_location=path");
    }
});

it('registers the 5 booking platforms with default_category=booking', function () {
    foreach (['fresha', 'booksy', 'timely', 'calendly', 'square'] as $key) {
        $config = config("sidest.social_platforms.{$key}");
        expect($config)->not->toBeNull("booking platform {$key} not registered");
        expect($config['default_category'])->toBe('booking');
        expect($config['handle_location'])->toBe('path');
        expect($config['url_template'])->toStartWith('https://');
    }
});

it('registers stan and skool as education path-mode platforms', function () {
    foreach (['stan', 'skool'] as $key) {
        $config = config("sidest.social_platforms.{$key}");
        expect($config)->not->toBeNull();
        expect($config['default_category'])->toBe('education');
        expect($config['handle_location'])->toBe('path');
    }
});
