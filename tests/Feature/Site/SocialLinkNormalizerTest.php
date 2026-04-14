<?php

use App\Services\Site\SocialLinkNormalizer;

function normalizer(): SocialLinkNormalizer
{
    return new SocialLinkNormalizer();
}

// --- getPublicRegistry() ---

it('returns 8 platforms in the public registry', function () {
    $registry = normalizer()->getPublicRegistry();

    expect($registry)->toHaveCount(8);
    expect(collect($registry)->pluck('key')->all())->toBe([
        'instagram', 'facebook', 'linkedin', 'youtube', 'tiktok', 'x', 'spotify', 'soundcloud',
    ]);
});

it('strips internal validation fields from the public registry', function () {
    $registry = normalizer()->getPublicRegistry();

    foreach ($registry as $entry) {
        expect($entry)->toHaveKeys(['key', 'display_name', 'icon_key', 'placeholder']);
        expect($entry)->not->toHaveKey('handle_pattern');
        expect($entry)->not->toHaveKey('host_allowlist');
        expect($entry)->not->toHaveKey('url_path_extractor');
        expect($entry)->not->toHaveKey('url_template');
    }
});

// --- isKnownPlatform() ---

it('recognises known platforms', function () {
    expect(normalizer()->isKnownPlatform('instagram'))->toBeTrue();
    expect(normalizer()->isKnownPlatform('x'))->toBeTrue();
    expect(normalizer()->isKnownPlatform('soundcloud'))->toBeTrue();
});

it('rejects unknown platforms', function () {
    expect(normalizer()->isKnownPlatform('myspace'))->toBeFalse();
    expect(normalizer()->isKnownPlatform(''))->toBeFalse();
});

// --- normalize(): handle path ---

it('normalizes a clean instagram handle', function () {
    $result = normalizer()->normalize('instagram', 'joshhunter', null);

    expect($result['url'])->toBe('https://instagram.com/joshhunter');
    expect($result['handle'])->toBe('joshhunter');
    expect($result['icon_key'])->toBe('instagram');
    expect($result['display_name'])->toBe('Instagram');
    expect($result['platform_key'])->toBe('instagram');
});

it('strips a leading @ from the handle', function () {
    $result = normalizer()->normalize('instagram', '@joshhunter', null);

    expect($result['handle'])->toBe('joshhunter');
    expect($result['url'])->toBe('https://instagram.com/joshhunter');
});

it('trims whitespace around the handle', function () {
    $result = normalizer()->normalize('instagram', '  joshhunter  ', null);

    expect($result['handle'])->toBe('joshhunter');
});

it('rejects a handle containing whitespace in the middle', function () {
    expect(fn () => normalizer()->normalize('instagram', 'josh hunter', null))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a handle with HTML injection attempts', function () {
    expect(fn () => normalizer()->normalize('instagram', '<script>alert(1)</script>', null))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a Cyrillic homoglyph handle', function () {
    // 'joshhunteг' uses Cyrillic 'г' (U+0433) instead of Latin 'r'
    expect(fn () => normalizer()->normalize('instagram', 'joshhunteг', null))
        ->toThrow(InvalidArgumentException::class);
});

it('enforces X handle length limit of 15 characters', function () {
    expect(fn () => normalizer()->normalize('x', 'aaaaaaaaaaaaaaaa', null))
        ->toThrow(InvalidArgumentException::class);

    $result = normalizer()->normalize('x', 'aaaaaaaaaaaaaaa', null);
    expect($result['handle'])->toBe('aaaaaaaaaaaaaaa');
});

it('builds tiktok URL with @ prefix in the path', function () {
    $result = normalizer()->normalize('tiktok', 'joshhunter', null);

    expect($result['url'])->toBe('https://tiktok.com/@joshhunter');
});

it('builds youtube URL with @ prefix in the path', function () {
    $result = normalizer()->normalize('youtube', 'joshhunter', null);

    expect($result['url'])->toBe('https://youtube.com/@joshhunter');
});

it('builds linkedin URL with /in/ prefix', function () {
    $result = normalizer()->normalize('linkedin', 'joshhunter', null);

    expect($result['url'])->toBe('https://linkedin.com/in/joshhunter');
});

// --- normalize(): URL path with extractable handle ---

it('normalizes a clean instagram URL with extractable handle', function () {
    $result = normalizer()->normalize('instagram', null, 'https://instagram.com/joshhunter');

    expect($result['url'])->toBe('https://instagram.com/joshhunter');
    expect($result['handle'])->toBe('joshhunter');
});

it('upgrades http to https on social URLs', function () {
    $result = normalizer()->normalize('instagram', null, 'http://instagram.com/joshhunter');

    expect($result['url'])->toBe('https://instagram.com/joshhunter');
});

it('strips www subdomain when extracting from a URL', function () {
    $result = normalizer()->normalize('instagram', null, 'https://www.instagram.com/joshhunter/');

    expect($result['url'])->toBe('https://instagram.com/joshhunter');
});

it('strips utm query params during URL normalization', function () {
    $result = normalizer()->normalize('instagram', null, 'https://www.instagram.com/joshhunter?utm_source=foo&utm_medium=bar');

    expect($result['url'])->toBe('https://instagram.com/joshhunter');
    expect($result['handle'])->toBe('joshhunter');
});

it('extracts handle from a tiktok URL with @ prefix', function () {
    $result = normalizer()->normalize('tiktok', null, 'https://www.tiktok.com/@joshhunter');

    expect($result['handle'])->toBe('joshhunter');
    expect($result['url'])->toBe('https://tiktok.com/@joshhunter');
});

it('extracts handle from a linkedin /in/ URL', function () {
    $result = normalizer()->normalize('linkedin', null, 'https://www.linkedin.com/in/joshhunter');

    expect($result['handle'])->toBe('joshhunter');
});

it('extracts handle from a linkedin /company/ URL', function () {
    $result = normalizer()->normalize('linkedin', null, 'https://www.linkedin.com/company/sidest');

    expect($result['handle'])->toBe('sidest');
});

it('accepts a twitter.com URL for the X platform', function () {
    $result = normalizer()->normalize('x', null, 'https://twitter.com/joshhunter');

    expect($result['url'])->toBe('https://x.com/joshhunter');
});

// --- normalize(): URL path with deep links (no handle extractable) ---

it('keeps an instagram post URL as-is when no handle is extractable', function () {
    $result = normalizer()->normalize('instagram', null, 'https://instagram.com/p/abc123def');

    expect($result['url'])->toBe('https://instagram.com/p/abc123def');
    expect($result['handle'])->toBeNull();
});

it('upgrades http to https even on deep links', function () {
    $result = normalizer()->normalize('instagram', null, 'http://instagram.com/p/abc123');

    expect($result['url'])->toBe('https://instagram.com/p/abc123');
});

// --- normalize(): URL path security rejections ---

it('rejects a URL on the wrong host', function () {
    expect(fn () => normalizer()->normalize('instagram', null, 'https://linktr.ee/joshhunter'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a punycode lookalike host', function () {
    // xn--instagram-... would be a homograph attack — host_allowlist is plain ASCII so it fails
    expect(fn () => normalizer()->normalize('instagram', null, 'https://xn--instagram-abc.com/joshhunter'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a malformed URL', function () {
    expect(fn () => normalizer()->normalize('instagram', null, 'not a url at all'))
        ->toThrow(InvalidArgumentException::class);
});

// --- normalize(): error cases ---

it('throws when neither handle nor URL is provided', function () {
    expect(fn () => normalizer()->normalize('instagram', null, null))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => normalizer()->normalize('instagram', '', ''))
        ->toThrow(InvalidArgumentException::class);
});

it('throws on unknown platform key', function () {
    expect(fn () => normalizer()->normalize('myspace', 'joshhunter', null))
        ->toThrow(InvalidArgumentException::class);
});

// --- extractHandleFromUrl() ---

it('extracts handle from a clean URL', function () {
    expect(normalizer()->extractHandleFromUrl('instagram', 'https://instagram.com/joshhunter'))
        ->toBe('joshhunter');
});

it('returns null for a deep-link URL with no extractable handle', function () {
    expect(normalizer()->extractHandleFromUrl('instagram', 'https://instagram.com/p/abc123'))
        ->toBeNull();
});

it('returns null for a wrong-host URL', function () {
    expect(normalizer()->extractHandleFromUrl('instagram', 'https://linktr.ee/joshhunter'))
        ->toBeNull();
});
