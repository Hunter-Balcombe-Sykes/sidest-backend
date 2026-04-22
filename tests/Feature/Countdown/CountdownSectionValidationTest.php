<?php

use App\Http\Requests\Api\Professional\Site\UpsertSectionBlockRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Direct form-request validation harness — no DB, no HTTP stack.
 * Matches the pattern used by tests/Feature/Newsletter/NewsletterSectionValidationTest.php.
 */
function validateCountdownUpsert(array $payload): array
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

it('accepts a countdown with no settings (draft with no timeline yet)', function () {
    // Affiliate can create the block in draft mode before they've decided
    // on a drop time. The publish gate (enforced elsewhere) blocks going
    // Live without a valid timeline.
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts a countdown with valid drop and expiry times', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'timeline' => [
                'drop_time' => '2026-05-01T20:00:00Z',
                'expiry_time' => '2026-05-03T20:00:00Z',
            ],
        ],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['settings']['timeline']['drop_time'])->toBe('2026-05-01T20:00:00Z');
});

it('rejects expiry_time equal to drop_time', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'timeline' => [
                'drop_time' => '2026-05-01T20:00:00Z',
                'expiry_time' => '2026-05-01T20:00:00Z',
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.timeline.expiry_time');
});

it('rejects expiry_time before drop_time', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'timeline' => [
                'drop_time' => '2026-05-03T20:00:00Z',
                'expiry_time' => '2026-05-01T20:00:00Z',
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.timeline.expiry_time');
});

it('rejects drop_time that is not a valid date', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'timeline' => [
                'drop_time' => 'not-a-date',
                'expiry_time' => '2026-05-03T20:00:00Z',
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.timeline.drop_time');
});

it('requires expiry_time when drop_time is provided', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'timeline' => [
                'drop_time' => '2026-05-01T20:00:00Z',
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.timeline.expiry_time');
});

it('requires drop_time when expiry_time is provided', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'timeline' => [
                'expiry_time' => '2026-05-03T20:00:00Z',
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.timeline.drop_time');
});
