<?php

use App\Services\Site\SocialLinkNormalizer;

function normalizer(): SocialLinkNormalizer
{
    return new SocialLinkNormalizer;
}

// --- getPublicRegistry() ---

it('returns 24 platforms in the public registry', function () {
    $registry = normalizer()->getPublicRegistry();

    expect($registry)->toHaveCount(24);
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

// --- Subdomain-mode normalization ---

it('normalizes a substack handle by subdomain', function () {
    $result = normalizer()->normalize('substack', 'joshhunter', null);

    expect($result['url'])->toBe('https://joshhunter.substack.com/');
    expect($result['handle'])->toBe('joshhunter');
    expect($result['icon_key'])->toBe('substack');
    expect($result['platform_key'])->toBe('substack');
});

it('strips leading @ in subdomain-mode handle input', function () {
    $result = normalizer()->normalize('substack', '@joshhunter', null);

    expect($result['handle'])->toBe('joshhunter');
    expect($result['url'])->toBe('https://joshhunter.substack.com/');
});

it('extracts the handle from a substack root URL', function () {
    $result = normalizer()->normalize('substack', null, 'https://joshhunter.substack.com/');

    expect($result['url'])->toBe('https://joshhunter.substack.com/');
    expect($result['handle'])->toBe('joshhunter');
});

it('extracts the handle from a bandcamp root URL', function () {
    $result = normalizer()->normalize('bandcamp', null, 'https://somebands.bandcamp.com');

    expect($result['handle'])->toBe('somebands');
    expect($result['url'])->toBe('https://somebands.bandcamp.com/');
});

it('extracts the handle for kajabi (mykajabi.com base)', function () {
    $result = normalizer()->normalize('kajabi', null, 'https://acmecoach.mykajabi.com/');

    expect($result['handle'])->toBe('acmecoach');
    expect($result['url'])->toBe('https://acmecoach.mykajabi.com/');
});

it('falls back to lenient URL storage on subdomain deep-link', function () {
    $result = normalizer()->normalize('substack', null, 'https://joshhunter.substack.com/p/my-post');

    // Deep link, handle not extracted
    expect($result['handle'])->toBeNull();
    // URL is preserved, https forced
    expect($result['url'])->toBe('https://joshhunter.substack.com/p/my-post');
});

it('forces https on http subdomain input', function () {
    $result = normalizer()->normalize('substack', null, 'http://joshhunter.substack.com/');

    expect($result['url'])->toBe('https://joshhunter.substack.com/');
});

it('rejects a labelled-suffix attack: evilsubstack.com must not match substack', function () {
    expect(fn () => normalizer()->normalize('substack', null, 'https://evilsubstack.com/fake'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a wrong-host URL for a subdomain platform', function () {
    expect(fn () => normalizer()->normalize('substack', null, 'https://alice.medium.com/'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a subdomain handle with invalid characters', function () {
    expect(fn () => normalizer()->normalize('substack', 'josh.hunter', null))
        ->toThrow(InvalidArgumentException::class); // dots not allowed per pattern
});

it('rejects a subdomain handle that is too short', function () {
    expect(fn () => normalizer()->normalize('substack', 'ab', null))
        ->toThrow(InvalidArgumentException::class); // min 3 chars
});

it('rejects the bare base domain as handle-less (no subdomain present)', function () {
    expect(fn () => normalizer()->normalize('substack', null, 'https://substack.com/'))
        ->toThrow(InvalidArgumentException::class);
});

it('normalizes a subdomain URL with mixed-case host', function () {
    $result = normalizer()->normalize('substack', null, 'https://JoshHunter.Substack.COM/');

    expect($result['url'])->toBe('https://joshhunter.substack.com/');
    expect($result['handle'])->toBe('joshhunter');
});

it('normalizes a subdomain URL with a trailing-dot FQDN host', function () {
    $result = normalizer()->normalize('substack', null, 'https://joshhunter.substack.com./');

    expect($result['url'])->toBe('https://joshhunter.substack.com/');
    expect($result['handle'])->toBe('joshhunter');
});

// --- Public registry: category exposure ---

it('exposes category in the public registry entries', function () {
    $registry = normalizer()->getPublicRegistry();

    foreach ($registry as $entry) {
        expect($entry)->toHaveKey('category');
        expect($entry['category'])->toBeIn(['social', 'booking', 'education', 'content', 'events', 'other']);
    }
});

it('maps instagram to category=social in the public registry', function () {
    $registry = normalizer()->getPublicRegistry();
    $instagram = collect($registry)->firstWhere('key', 'instagram');

    expect($instagram['category'])->toBe('social');
});

it('maps calendly to category=booking in the public registry', function () {
    $registry = normalizer()->getPublicRegistry();
    $calendly = collect($registry)->firstWhere('key', 'calendly');

    expect($calendly['category'])->toBe('booking');
});

it('still strips internal validation fields including handle_location', function () {
    $registry = normalizer()->getPublicRegistry();

    foreach ($registry as $entry) {
        expect($entry)->not->toHaveKey('handle_pattern');
        expect($entry)->not->toHaveKey('host_allowlist');
        expect($entry)->not->toHaveKey('url_path_extractor');
        expect($entry)->not->toHaveKey('url_template');
        expect($entry)->not->toHaveKey('handle_location');
        expect($entry)->not->toHaveKey('default_category'); // renamed to `category` in output
    }
});
