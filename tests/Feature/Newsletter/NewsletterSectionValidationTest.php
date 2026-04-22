<?php

use App\Http\Requests\Api\Professional\Site\UpsertSectionBlockRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Settings-shape validation for the newsletter section. Uses the direct
 * form-request pattern (no DB, no HTTP stack) — same pattern as
 * tests/Feature/Site/LinkBlockSocialValidationTest.php.
 */
function validateNewsletterUpsert(array $payload): array
{
    $request = Request::create('/api/test', 'POST', $payload);
    $formRequest = UpsertSectionBlockRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['ok' => true, 'data' => $formRequest->validated()];
    } catch (ValidationException $e) {
        return ['ok' => false, 'errors' => $e->errors()];
    }
}

it('accepts a newsletter block with no settings (uses defaults)', function () {
    $result = validateNewsletterUpsert([
        'block_type' => 'newsletter',
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts a newsletter block with full settings', function () {
    $result = validateNewsletterUpsert([
        'block_type' => 'newsletter',
        'settings' => [
            'headline' => 'Join my newsletter',
            'description' => 'Weekly tips and exclusive discount codes.',
            'cta_label' => 'Subscribe',
            'list_key' => 'marketing',
        ],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['settings']['headline'])->toBe('Join my newsletter');
});

it('rejects an overly long headline', function () {
    $result = validateNewsletterUpsert([
        'block_type' => 'newsletter',
        'settings' => [
            'headline' => str_repeat('a', 81),
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.headline');
});

it('rejects an overly long description', function () {
    $result = validateNewsletterUpsert([
        'block_type' => 'newsletter',
        'settings' => [
            'description' => str_repeat('a', 201),
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.description');
});

it('strips HTML tags from newsletter copy fields (defense-in-depth)', function () {
    // strip_tags removes tags but preserves inner text — same behavior as the
    // existing settings.text sanitization. The frontend is the primary XSS
    // defense via auto-escaping on render; this is just belt-and-braces.
    $result = validateNewsletterUpsert([
        'block_type' => 'newsletter',
        'settings' => [
            'headline' => 'Join <b>my</b> newsletter',
            'description' => 'Weekly tips <em>and</em> codes',
            'cta_label' => '<img>Go',
        ],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['settings']['headline'])->toBe('Join my newsletter');
    expect($result['data']['settings']['description'])->toBe('Weekly tips and codes');
    expect($result['data']['settings']['cta_label'])->toBe('Go');
});

it('rejects a list_key with invalid characters', function () {
    $result = validateNewsletterUpsert([
        'block_type' => 'newsletter',
        'settings' => [
            'list_key' => 'has spaces!',
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.list_key');
});

it('accepts a valid slug-shaped list_key', function () {
    $result = validateNewsletterUpsert([
        'block_type' => 'newsletter',
        'settings' => [
            'list_key' => 'vip-announcements',
        ],
    ]);

    expect($result['ok'])->toBeTrue();
});
