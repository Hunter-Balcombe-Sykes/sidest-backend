# Countdown Section Block Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a new `countdown` section block type that lets affiliates declare a time-bounded window (drop_time → expiry_time) with per-state copy and CTAs, using only the existing section-block machinery.

**Architecture:** Reuse the singleton section-block pattern. Register `'countdown'` in the section-type allowlist, gate availability for brand/professional/influencer accounts, add countdown-specific validation rules to the shared `UpsertSectionBlockRequest`, and add a visibility requirement so a countdown can't go Live without a valid timeline. No new controller, model, migration, route, or resource — the block is just a new `block_type` value in `site.blocks` with countdown-specific shape stored in the JSONB `settings` column. Lifecycle state (pre-drop / live / expired) is derived on the client; the server stores raw UTC timestamps.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4 + PHPUnit, PostgreSQL (via Supabase) with JSONB `settings` column, existing `Block` model and `ProfessionalSectionBlockController`.

**Spec:** [`docs/superpowers/specs/2026-04-22-countdown-section-block-design.md`](../specs/2026-04-22-countdown-section-block-design.md)

---

## Files Touched

### Modify

| File | Change |
|---|---|
| `config/sidest.php` | Add `'countdown'` to `section_block_types`; add `'countdown'` to `account_type_defaults.influencer.allowed_sections` and `account_type_defaults.brand.allowed_sections` (professional inherits from influencer). |
| `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php` | Add countdown-specific validation rules (applied conditionally when `block_type === 'countdown'`) and extend `prepareForValidation()` to sanitize countdown string fields. |
| `app/Services/Professional/SectionVisibilityService.php` | Add `checkCountdownRequirements()` that blocks publish-to-live when settings timeline is missing/malformed. Wire it into the `match` in `checkVisibilityRequirements()`. |

### Create

| File | Purpose |
|---|---|
| `tests/Feature/Countdown/CountdownSectionConfigTest.php` | Asserts countdown is registered in config and enabled for brand/professional/influencer. |
| `tests/Feature/Countdown/CountdownSectionValidationTest.php` | Direct form-request validation tests (fast, no DB, no HTTP). Covers all rule cases. |
| `tests/Feature/Countdown/CountdownSectionBehaviorTest.php` | HTTP-layer integration: upsert flow, recursive settings merge, publish gate, public-site payload inclusion/exclusion. |

---

## Task 1: Register countdown in section_block_types config

**Files:**
- Create: `tests/Feature/Countdown/CountdownSectionConfigTest.php`
- Modify: `config/sidest.php:405`

- [ ] **Step 1: Write the failing config test**

Create `tests/Feature/Countdown/CountdownSectionConfigTest.php`:

```php
<?php

it('registers countdown as a section_block_type', function () {
    expect(config('sidest.section_block_types'))->toContain('countdown');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionConfigTest.php
```

Expected: FAIL — `countdown` not in the array.

- [ ] **Step 3: Add countdown to the allowlist**

Edit `config/sidest.php` line 405. The current line is:

```php
'section_block_types' => ['gallery', 'services', 'shop', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter'],
```

Change to:

```php
'section_block_types' => ['gallery', 'services', 'shop', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter', 'countdown'],
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionConfigTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/sidest.php tests/Feature/Countdown/CountdownSectionConfigTest.php
git commit -m "feat(countdown): register countdown as a section block type"
```

---

## Task 2: Enable countdown for brand, professional, and influencer account types

**Files:**
- Modify: `tests/Feature/Countdown/CountdownSectionConfigTest.php` (append tests)
- Modify: `config/sidest.php:470` (influencer), `config/sidest.php:491` (brand). Professional inherits from influencer, so no direct edit needed there — but we still assert it in tests.

- [ ] **Step 1: Append failing account-type availability tests**

Append to `tests/Feature/Countdown/CountdownSectionConfigTest.php`:

```php
it('allows countdown for influencer account type', function () {
    expect(config('sidest.account_type_defaults.influencer.allowed_sections'))
        ->toContain('countdown');
});

it('allows countdown for professional account type', function () {
    expect(config('sidest.account_type_defaults.professional.allowed_sections'))
        ->toContain('countdown');
});

it('allows countdown for brand account type', function () {
    expect(config('sidest.account_type_defaults.brand.allowed_sections'))
        ->toContain('countdown');
});

it('does NOT auto-provision countdown in default_sections', function () {
    // Countdown is opt-in — affiliates configure the timeline when they
    // want one, not an empty-by-default block sitting on the page.
    foreach (['influencer', 'professional', 'brand'] as $type) {
        expect(config("sidest.account_type_defaults.{$type}.default_sections"))
            ->not->toContain('countdown');
    }
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionConfigTest.php
```

Expected: 4 new FAILs ("does not contain countdown" for influencer/professional/brand; last test passes already since countdown isn't in any default_sections).

- [ ] **Step 3: Add countdown to influencer + professional + brand allowed_sections**

Edit `config/sidest.php`:

**Line 470** (influencer — base type):

```php
'allowed_sections' => ['shop', 'services', 'gallery', 'documents', 'newsletter'],
```

Change to:

```php
'allowed_sections' => ['shop', 'services', 'gallery', 'documents', 'newsletter', 'countdown'],
```

**Line 486** (professional — explicit list, does not inherit this key even though `inherits => influencer` is set, because the professional block redefines `allowed_sections`):

```php
'allowed_sections' => ['shop', 'services', 'gallery', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter'],
```

Change to:

```php
'allowed_sections' => ['shop', 'services', 'gallery', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter', 'countdown'],
```

**Line 491** (brand):

```php
'allowed_sections' => ['shop', 'services', 'gallery', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'newsletter'],
```

Change to:

```php
'allowed_sections' => ['shop', 'services', 'gallery', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'newsletter', 'countdown'],
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionConfigTest.php
```

Expected: all 5 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add config/sidest.php tests/Feature/Countdown/CountdownSectionConfigTest.php
git commit -m "feat(countdown): enable countdown for brand, professional, influencer account types"
```

---

## Task 3: Validate countdown timeline (drop_time, expiry_time)

**Files:**
- Create: `tests/Feature/Countdown/CountdownSectionValidationTest.php`
- Modify: `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php`

- [ ] **Step 1: Create the validation test file with failing tests**

Create `tests/Feature/Countdown/CountdownSectionValidationTest.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail appropriately**

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionValidationTest.php
```

Expected: tests pass for "no settings" (no rules hit) but the rejection tests incorrectly pass too because no countdown rules exist yet. Specifically:
- "accepts a countdown with valid drop and expiry times" — PASSES (no rule, so nothing rejects it).
- All four "rejects..." and "requires..." tests — FAIL (we expect rejection but currently it's accepted).

This is the failing-test state we need.

- [ ] **Step 3: Add countdown timeline validation to the form request**

Edit `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php`. Inside `rules()`, after the existing `$textRules` block (around line 22), add countdown-specific rules. The final `rules()` method should look like this:

```php
public function rules(): array
{
    $type = (string) $this->input('block_type');
    $allowed = config('sidest.section_block_types', []);

    $textRules = ['sometimes', 'nullable', 'string', 'max:4000'];

    // Enforce 200 words for these section types
    if (in_array($type, ['bio', 'promotional_text'], true)) {
        $textRules[] = new MaxWords(200);
    }

    $rules = [
        'block_type' => ['required', 'string', Rule::in($allowed)],
        'title' => ['sometimes', 'nullable', 'string', 'max:100'],
        'is_active' => ['sometimes', 'boolean'],
        'is_enabled' => ['sometimes', 'boolean'],
        'publication_state' => ['sometimes', 'string', Rule::in(['live', 'draft'])],
        'settings' => ['sometimes', 'array'],
        'settings.text' => $textRules,

        // Newsletter section — configurable copy for the signup form.
        'settings.headline' => ['sometimes', 'nullable', 'string', 'max:80'],
        'settings.description' => ['sometimes', 'nullable', 'string', 'max:200'],
        'settings.cta_label' => ['sometimes', 'nullable', 'string', 'max:40'],
        'settings.list_key' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]{0,39}$/'],
    ];

    if ($type === 'countdown') {
        $rules = array_merge($rules, $this->countdownRules());
    }

    return $rules;
}

/**
 * Countdown-specific settings shape:
 *   settings.title                    — optional, max 80
 *   settings.timeline.drop_time       — required-with-expiry, ISO-8601 date
 *   settings.timeline.expiry_time     — required-with-drop, ISO-8601, strictly after drop
 *   settings.states.{state}.*         — optional per-state copy + CTA (added in later tasks)
 *
 * Returning as an array and array_merge-ing keeps the base rules shape stable
 * and lets us reason about countdown rules in isolation.
 *
 * @return array<string, array<int, mixed>>
 */
private function countdownRules(): array
{
    return [
        'settings.title' => ['sometimes', 'nullable', 'string', 'max:80'],
        'settings.timeline' => ['sometimes', 'array'],
        'settings.timeline.drop_time' => ['sometimes', 'required_with:settings.timeline.expiry_time', 'date'],
        'settings.timeline.expiry_time' => ['sometimes', 'required_with:settings.timeline.drop_time', 'date', 'after:settings.timeline.drop_time'],
    ];
}
```

**Important:** When adding the `countdownRules()` method, place it directly after `rules()` and before `prepareForValidation()`. The existing `title` key at the top level (`'title' => ['sometimes', 'nullable', 'string', 'max:100']`) is a different concept (block title at the top of settings payload) — countdown uses `settings.title` to stay namespaced under settings, which is where all type-specific config lives. Don't confuse them.

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionValidationTest.php
```

Expected: all 7 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php tests/Feature/Countdown/CountdownSectionValidationTest.php
git commit -m "feat(countdown): validate timeline (drop_time + expiry_time with expiry>drop)"
```

---

## Task 4: Validate countdown title and per-state copy

**Files:**
- Modify: `tests/Feature/Countdown/CountdownSectionValidationTest.php` (append tests)
- Modify: `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php` (extend `countdownRules()`)

- [ ] **Step 1: Append failing tests for title + state copy**

Append to `tests/Feature/Countdown/CountdownSectionValidationTest.php`:

```php
it('accepts a full per-state countdown payload', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'title' => 'The Drop',
            'timeline' => [
                'drop_time' => '2026-05-01T20:00:00Z',
                'expiry_time' => '2026-05-03T20:00:00Z',
            ],
            'states' => [
                'pre_drop' => [
                    'headline' => 'Coming Friday',
                    'subtitle' => 'A limited run of three new knits.',
                ],
                'live' => [
                    'headline' => "It's live",
                    'subtitle' => "Shop now before they're gone.",
                ],
                'expired' => [
                    'headline' => null,
                    'subtitle' => null,
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['settings']['title'])->toBe('The Drop');
    expect($result['data']['settings']['states']['live']['headline'])->toBe("It's live");
});

it('rejects a title longer than 80 chars', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'title' => str_repeat('a', 81),
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.title');
});

it('rejects a state headline longer than 80 chars', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => ['headline' => str_repeat('a', 81)],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.live.headline');
});

it('rejects a state subtitle longer than 200 chars', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'pre_drop' => ['subtitle' => str_repeat('a', 201)],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.pre_drop.subtitle');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionValidationTest.php
```

Expected: new tests FAIL (rules don't exist yet).

- [ ] **Step 3: Extend countdownRules() with title + state copy rules**

Edit `countdownRules()` in `UpsertSectionBlockRequest.php` to return:

```php
private function countdownRules(): array
{
    $rules = [
        'settings.title' => ['sometimes', 'nullable', 'string', 'max:80'],
        'settings.timeline' => ['sometimes', 'array'],
        'settings.timeline.drop_time' => ['sometimes', 'required_with:settings.timeline.expiry_time', 'date'],
        'settings.timeline.expiry_time' => ['sometimes', 'required_with:settings.timeline.drop_time', 'date', 'after:settings.timeline.drop_time'],
        'settings.states' => ['sometimes', 'array'],
    ];

    // Per-state copy rules: same shape for all three states, so build them
    // programmatically rather than writing 12 near-identical lines.
    foreach (['pre_drop', 'live', 'expired'] as $state) {
        $rules["settings.states.{$state}"] = ['sometimes', 'array'];
        $rules["settings.states.{$state}.headline"] = ['sometimes', 'nullable', 'string', 'max:80'];
        $rules["settings.states.{$state}.subtitle"] = ['sometimes', 'nullable', 'string', 'max:200'];
    }

    return $rules;
}
```

- [ ] **Step 4: Run tests to verify all pass**

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionValidationTest.php
```

Expected: all 11 tests PASS (7 from task 3 + 4 new).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php tests/Feature/Countdown/CountdownSectionValidationTest.php
git commit -m "feat(countdown): validate title and per-state copy (headline, subtitle)"
```

---

## Task 5: Validate per-state CTAs (label, url, scheme allowlist)

**Files:**
- Modify: `tests/Feature/Countdown/CountdownSectionValidationTest.php` (append tests)
- Modify: `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php` (extend `countdownRules()`)

- [ ] **Step 1: Append failing CTA validation tests**

Append to `tests/Feature/Countdown/CountdownSectionValidationTest.php`:

```php
it('accepts a CTA with https URL', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => 'Shop the drop', 'url' => 'https://stan.store/foo'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts a CTA with a hash anchor URL (internal section link)', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => 'Shop now', 'url' => '#shop?products=abc,def'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts a CTA with an absolute path URL', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => 'Go', 'url' => '/some/page'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeTrue();
});

it('rejects a javascript: URL', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => 'Bad', 'url' => 'javascript:alert(1)'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.live.cta.url');
});

it('rejects a mailto: URL', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => 'Bad', 'url' => 'mailto:foo@example.com'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.live.cta.url');
});

it('rejects a protocol-relative URL', function () {
    // //example.com is protocol-relative and inherits the current scheme.
    // Reject it — if someone needs an external URL, they should be explicit.
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => 'Bad', 'url' => '//evil.example.com'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.live.cta.url');
});

it('rejects a CTA label without a URL', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => 'Shop now'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.live.cta.url');
});

it('rejects a CTA URL without a label', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['url' => 'https://example.com'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.live.cta.label');
});

it('rejects a CTA label longer than 40 chars', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => str_repeat('a', 41), 'url' => 'https://x.test'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.live.cta.label');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionValidationTest.php
```

Expected: 9 new tests FAIL (CTA rules don't exist).

- [ ] **Step 3: Extend countdownRules() with CTA validation**

Replace the foreach block inside `countdownRules()` so each state also gets CTA rules. The full method should now be:

```php
private function countdownRules(): array
{
    $rules = [
        'settings.title' => ['sometimes', 'nullable', 'string', 'max:80'],
        'settings.timeline' => ['sometimes', 'array'],
        'settings.timeline.drop_time' => ['sometimes', 'required_with:settings.timeline.expiry_time', 'date'],
        'settings.timeline.expiry_time' => ['sometimes', 'required_with:settings.timeline.drop_time', 'date', 'after:settings.timeline.drop_time'],
        'settings.states' => ['sometimes', 'array'],
    ];

    // URL scheme allowlist: https?://, absolute path (not protocol-relative //),
    // or hash anchor. Rejects javascript:, data:, mailto:, and protocol-relative URLs.
    $urlPattern = '/^(https?:\/\/\S+|\/(?!\/)\S*|#\S*)$/i';

    foreach (['pre_drop', 'live', 'expired'] as $state) {
        $rules["settings.states.{$state}"] = ['sometimes', 'array'];
        $rules["settings.states.{$state}.headline"] = ['sometimes', 'nullable', 'string', 'max:80'];
        $rules["settings.states.{$state}.subtitle"] = ['sometimes', 'nullable', 'string', 'max:200'];
        $rules["settings.states.{$state}.cta"] = ['sometimes', 'array'];
        $rules["settings.states.{$state}.cta.label"] = [
            'sometimes',
            'nullable',
            'string',
            'max:40',
            "required_with:settings.states.{$state}.cta.url",
        ];
        $rules["settings.states.{$state}.cta.url"] = [
            'sometimes',
            'nullable',
            'string',
            'max:2048',
            "required_with:settings.states.{$state}.cta.label",
            "regex:{$urlPattern}",
        ];
    }

    return $rules;
}
```

**Note on `required_with` semantics:** Laravel's `required_with` fires only when the paired field is *present and non-empty*. So `{ cta: { label: null, url: null } }` is valid (both null, neither triggers the other). `{ cta: { label: "Go" } }` without `url` fires `required_with` and rejects. That matches what the tests expect.

- [ ] **Step 4: Run tests to verify all pass**

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionValidationTest.php
```

Expected: all 20 tests PASS (11 previous + 9 new).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php tests/Feature/Countdown/CountdownSectionValidationTest.php
git commit -m "feat(countdown): validate per-state CTAs with scheme allowlist"
```

---

## Task 6: Sanitize countdown string fields (strip HTML tags)

**Files:**
- Modify: `tests/Feature/Countdown/CountdownSectionValidationTest.php` (append test)
- Modify: `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php` (extend `prepareForValidation()`)

- [ ] **Step 1: Append failing sanitization test**

Append to `tests/Feature/Countdown/CountdownSectionValidationTest.php`:

```php
it('strips HTML tags from countdown string fields (defense-in-depth)', function () {
    // Matches the newsletter/bio sanitization pattern. Frontend auto-escape is
    // the primary XSS defense; this prevents stored tags from reaching a
    // future buggy renderer.
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'title' => 'The <b>Drop</b>',
            'states' => [
                'live' => [
                    'headline' => '<script>alert(1)</script>Live now',
                    'subtitle' => 'Shop <em>now</em>',
                    'cta' => [
                        'label' => '<img>Go',
                        'url' => 'https://example.com',
                    ],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['settings']['title'])->toBe('The Drop');
    expect($result['data']['settings']['states']['live']['headline'])->toBe('alert(1)Live now');
    expect($result['data']['settings']['states']['live']['subtitle'])->toBe('Shop now');
    expect($result['data']['settings']['states']['live']['cta']['label'])->toBe('Go');
});
```

**Note on the `<script>` assertion:** `strip_tags` removes tags but preserves the text content between them. `<script>alert(1)</script>Live now` becomes `alert(1)Live now` — the script tags are gone but the word `alert(1)` remains as plain text. This is fine: the primary defense is the frontend's auto-escape on render, and this sanitization is belt-and-braces against an accidentally unescaped renderer path. A reviewer might find `alert(1)` in the output surprising; that's the expected behavior of `strip_tags`, and it matches how newsletter/bio fields handle the same case.

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionValidationTest.php --filter="strips HTML"
```

Expected: FAIL — tags not stripped yet from countdown-specific paths.

- [ ] **Step 3: Extend prepareForValidation() to sanitize countdown string fields**

Edit `prepareForValidation()` in `UpsertSectionBlockRequest.php`. After the existing newsletter sanitization loop (around line 78), add a countdown-specific sanitization block. The full method should become:

```php
protected function prepareForValidation(): void
{
    $blockType = $this->route('blockType') ?? $this->route('block_type') ?? $this->route('type');
    if (is_string($blockType)) {
        $this->merge(['block_type' => strtolower(trim($blockType))]);
    }

    $publicationState = $this->input('publication_state');
    if (is_string($publicationState)) {
        $this->merge(['publication_state' => strtolower(trim($publicationState))]);
    }

    // normalize text — strip HTML tags before validation to prevent stored XSS
    if (is_string(data_get($this->input('settings', []), 'text'))) {
        $t = trim(strip_tags((string) data_get($this->input('settings'), 'text')));
        $settings = $this->input('settings', []);
        $settings['text'] = ($t === '') ? null : $t;
        $this->merge(['settings' => $settings]);
    }

    // Newsletter copy fields — same strip_tags defense-in-depth.
    $settings = $this->input('settings', []);
    $settingsChanged = false;
    foreach (['headline', 'description', 'cta_label'] as $key) {
        if (is_string(data_get($settings, $key))) {
            $cleaned = trim(strip_tags((string) $settings[$key]));
            $settings[$key] = ($cleaned === '') ? null : $cleaned;
            $settingsChanged = true;
        }
    }
    if ($settingsChanged) {
        $this->merge(['settings' => $settings]);
    }

    // Countdown sanitization — title + per-state headline/subtitle/cta.label.
    // Same strip_tags pattern. Run only when block_type is countdown to avoid
    // mutating settings shapes that happen to share key names.
    if ($this->input('block_type') === 'countdown') {
        $this->sanitizeCountdownSettings();
    }
}

/**
 * Strip HTML tags from all user-authored string fields in countdown settings.
 * Mirrors the bio/newsletter pattern: defense-in-depth against a future
 * renderer that forgets to escape. Leaves null/missing values alone.
 */
private function sanitizeCountdownSettings(): void
{
    $settings = $this->input('settings', []);
    if (! is_array($settings)) {
        return;
    }

    $clean = static function (mixed $value): mixed {
        if (! is_string($value)) {
            return $value;
        }
        $stripped = trim(strip_tags($value));

        return $stripped === '' ? null : $stripped;
    };

    if (array_key_exists('title', $settings)) {
        $settings['title'] = $clean($settings['title']);
    }

    foreach (['pre_drop', 'live', 'expired'] as $state) {
        if (! isset($settings['states'][$state]) || ! is_array($settings['states'][$state])) {
            continue;
        }

        foreach (['headline', 'subtitle'] as $field) {
            if (array_key_exists($field, $settings['states'][$state])) {
                $settings['states'][$state][$field] = $clean($settings['states'][$state][$field]);
            }
        }

        if (isset($settings['states'][$state]['cta']) && is_array($settings['states'][$state]['cta'])) {
            if (array_key_exists('label', $settings['states'][$state]['cta'])) {
                $settings['states'][$state]['cta']['label'] = $clean($settings['states'][$state]['cta']['label']);
            }
            // Do NOT strip_tags the URL — tags in URLs are invalid anyway, and
            // the scheme-allowlist regex already rejects dangerous values.
        }
    }

    $this->merge(['settings' => $settings]);
}
```

- [ ] **Step 4: Run tests to verify all pass**

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionValidationTest.php
```

Expected: all 21 tests PASS (20 previous + 1 new).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php tests/Feature/Countdown/CountdownSectionValidationTest.php
git commit -m "feat(countdown): strip HTML tags from countdown string fields"
```

---

## Task 7: Gate publish-to-live on a valid timeline

**Files:**
- Create: `tests/Feature/Countdown/CountdownSectionBehaviorTest.php`
- Modify: `app/Services/Professional/SectionVisibilityService.php`

The visibility service already blocks publishing a gallery without images, a services section without a priced service, etc. We extend it so a countdown can't go Live until its timeline is set to a valid (drop < expiry, expiry in the future) window.

- [ ] **Step 1: Create the behavior test file with a failing publish-gate test**

First, look at an existing feature test that authenticates a professional and calls the site sections API. Inspect:

```bash
ls tests/Feature/Newsletter/
```

The newsletter tests don't make HTTP calls. Let's use an existing authenticated-section test as a reference:

```bash
grep -rn "PUT.*sections/" tests/Feature/ | head -5
```

If you don't find a close pattern, use the pattern below, which mirrors how `ProfessionalAboutTest.php` drives its HTTP flow.

Create `tests/Feature/Countdown/CountdownSectionBehaviorTest.php`:

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Services\Professional\AccountTypeDefaultsService;
use Illuminate\Support\Str;

/**
 * Spins up a minimal professional + site for each test and returns both.
 * Uses the same account-type-defaults pipeline production does, so account-type
 * gating is exercised realistically.
 */
function makeCountdownPro(string $professionalType = 'brand'): array
{
    $pro = Professional::query()->create([
        'id' => (string) Str::uuid(),
        'professional_type' => $professionalType,
        'handle' => 'countdown-test-' . Str::random(6),
        'display_name' => 'Countdown Test',
        'auth_user_id' => (string) Str::uuid(),
    ]);

    $site = Site::query()->create([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
        'handle' => $pro->handle,
        'settings' => [],
    ]);

    app(AccountTypeDefaultsService::class)->applyDefaults($pro, $site);

    return [$pro, $site];
}

it('rejects publish-to-live when countdown has no timeline', function () {
    [$pro, $site] = makeCountdownPro('brand');

    $response = $this->actingAs($pro, 'professional')
        ->putJson("/api/professional/sites/{$site->id}/sections/countdown", [
            'publication_state' => 'live',
        ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('timeline');
});
```

Note: you may need to adjust the auth helper (`actingAs(..., 'professional')`) and route prefix to match this repo's patterns. Check `tests/Feature/Newsletter/` or `tests/Feature/ProfessionalAboutTest.php` for the canonical pattern. If the repo uses a custom test-auth helper (e.g., `$this->actingAsProfessional($pro)`), use that instead.

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionBehaviorTest.php --filter="rejects publish"
```

Expected: FAIL with a 200/201 (publish succeeds despite no timeline).

- [ ] **Step 3: Add countdown visibility gate**

Edit `app/Services/Professional/SectionVisibilityService.php`. Add `'countdown'` to the match in `checkVisibilityRequirements()` and implement `checkCountdownRequirements()`:

```php
public function checkVisibilityRequirements(
    string $professionalId,
    string $siteId,
    string $blockType
): array {
    return match ($blockType) {
        'gallery' => $this->checkGalleryRequirements($siteId),
        'booking' => $this->checkBookingRequirements($professionalId),
        'services' => $this->checkServicesRequirements($professionalId),
        'documents' => $this->checkDocumentsRequirements($siteId),
        'countdown' => $this->checkCountdownRequirements($professionalId, $siteId),
        default => [true, null],
    };
}

/**
 * A countdown is publishable when it has both a drop_time and an expiry_time,
 * with expiry strictly after drop, AND the expiry has not already elapsed.
 * Checks the settings JSON on the stored block; returns [false, reason] on
 * any failure so the frontend can show the publish-button tooltip.
 */
private function checkCountdownRequirements(string $professionalId, string $siteId): array
{
    $block = Block::query()
        ->where('professional_id', $professionalId)
        ->where('site_id', $siteId)
        ->where('block_group', 'sections')
        ->where('block_type', 'countdown')
        ->first();

    if (! $block) {
        return [false, 'Countdown section requires a drop time and expiry time before it can go live.'];
    }

    $settings = is_array($block->settings) ? $block->settings : [];
    $drop = data_get($settings, 'timeline.drop_time');
    $expiry = data_get($settings, 'timeline.expiry_time');

    if (! is_string($drop) || ! is_string($expiry) || $drop === '' || $expiry === '') {
        return [false, 'Countdown section requires a drop time and expiry time before it can go live.'];
    }

    try {
        $dropTs = \Carbon\CarbonImmutable::parse($drop);
        $expiryTs = \Carbon\CarbonImmutable::parse($expiry);
    } catch (\Throwable) {
        return [false, 'Countdown section has an invalid drop time or expiry time.'];
    }

    if ($expiryTs->lessThanOrEqualTo($dropTs)) {
        return [false, 'Countdown expiry time must be after the drop time.'];
    }

    if ($expiryTs->isPast()) {
        return [false, 'Countdown expiry time is already in the past.'];
    }

    return [true, null];
}
```

**Critical:** the publish gate runs *before* the upsert writes the new settings, which means on the FIRST upsert with both `publication_state: 'live'` AND the timeline in the same payload, the gate reads the OLD settings (which lack a timeline) and rejects. This is a UX bug.

Look at `ProfessionalSectionBlockController::upsert()` at lines 94-106 — it calls `checkVisibilityRequirements` before the `DB::transaction` that persists settings. For other block types (gallery, services, etc.) the requirements are external resources (images, services), so there's no ordering hazard. Countdown is different because the requirement lives in the payload itself.

**Fix in this task:** modify `ProfessionalSectionBlockController::upsert()` to pass the incoming settings through to the visibility check for countdown specifically, OR reorder so the gate runs against the *merged* settings. The cleanest fix is to let the visibility service accept an optional "pending settings" argument and use it when provided:

Modify `SectionVisibilityService::checkVisibilityRequirements()` signature to accept an optional pending-settings array:

```php
public function checkVisibilityRequirements(
    string $professionalId,
    string $siteId,
    string $blockType,
    ?array $pendingSettings = null
): array {
    return match ($blockType) {
        'gallery' => $this->checkGalleryRequirements($siteId),
        'booking' => $this->checkBookingRequirements($professionalId),
        'services' => $this->checkServicesRequirements($professionalId),
        'documents' => $this->checkDocumentsRequirements($siteId),
        'countdown' => $this->checkCountdownRequirements($professionalId, $siteId, $pendingSettings),
        default => [true, null],
    };
}
```

And update `checkCountdownRequirements()` to prefer the pending settings when provided:

```php
private function checkCountdownRequirements(string $professionalId, string $siteId, ?array $pendingSettings = null): array
{
    // Prefer the incoming-but-not-yet-persisted settings so a first-time publish
    // with timeline + publication_state=live in the same payload works. Fall
    // back to the stored settings otherwise (e.g., republishing a previously-
    // saved timeline).
    if ($pendingSettings === null) {
        $block = Block::query()
            ->where('professional_id', $professionalId)
            ->where('site_id', $siteId)
            ->where('block_group', 'sections')
            ->where('block_type', 'countdown')
            ->first();

        $stored = $block && is_array($block->settings) ? $block->settings : [];
        $settings = $stored;
    } else {
        // Merge pending over stored so partial PATCHes still see unchanged fields.
        $block = Block::query()
            ->where('professional_id', $professionalId)
            ->where('site_id', $siteId)
            ->where('block_group', 'sections')
            ->where('block_type', 'countdown')
            ->first();

        $stored = $block && is_array($block->settings) ? $block->settings : [];
        $settings = array_replace_recursive($stored, $pendingSettings);
    }

    $drop = data_get($settings, 'timeline.drop_time');
    $expiry = data_get($settings, 'timeline.expiry_time');

    if (! is_string($drop) || ! is_string($expiry) || $drop === '' || $expiry === '') {
        return [false, 'Countdown section requires a drop time and expiry time before it can go live.'];
    }

    try {
        $dropTs = \Carbon\CarbonImmutable::parse($drop);
        $expiryTs = \Carbon\CarbonImmutable::parse($expiry);
    } catch (\Throwable) {
        return [false, 'Countdown section has an invalid drop time or expiry time.'];
    }

    if ($expiryTs->lessThanOrEqualTo($dropTs)) {
        return [false, 'Countdown expiry time must be after the drop time.'];
    }

    if ($expiryTs->isPast()) {
        return [false, 'Countdown expiry time is already in the past.'];
    }

    return [true, null];
}
```

Now edit `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalSectionBlockController.php` — the `upsert()` method. Around line 98, update the visibility check call site to pass the incoming settings:

Change:

```php
if ($isPublishing) {
    [$canBeVisible, $reason] = $this->visibilityService->checkVisibilityRequirements(
        (string) $pro->id,
        (string) $site->id,
        $blockType
    );
    if (! $canBeVisible) {
        return $this->error($reason, 422);
    }
}
```

To:

```php
if ($isPublishing) {
    [$canBeVisible, $reason] = $this->visibilityService->checkVisibilityRequirements(
        (string) $pro->id,
        (string) $site->id,
        $blockType,
        is_array($data['settings'] ?? null) ? $data['settings'] : null,
    );
    if (! $canBeVisible) {
        return $this->error($reason, 422);
    }
}
```

The other two call sites of `checkVisibilityRequirements` (in `AccountTypeDefaultsService::applyDefaults()` line 61 and in `ProfessionalSectionBlockController::serializeSection()` line 249, plus inside `SectionVisibilityService::reevaluateEnabled()` line 52) continue to pass no pending settings — they read from the stored block, which is the correct behavior for those paths.

- [ ] **Step 4: Run behavior test again + append positive test**

Append to `tests/Feature/Countdown/CountdownSectionBehaviorTest.php`:

```php
it('accepts publish-to-live when countdown has a valid timeline in the same payload', function () {
    [$pro, $site] = makeCountdownPro('brand');

    $response = $this->actingAs($pro, 'professional')
        ->putJson("/api/professional/sites/{$site->id}/sections/countdown", [
            'publication_state' => 'live',
            'settings' => [
                'timeline' => [
                    'drop_time' => now()->addDays(1)->toIso8601String(),
                    'expiry_time' => now()->addDays(3)->toIso8601String(),
                ],
            ],
        ]);

    $response->assertOk();
    expect($response->json('section.publication_state'))->toBe('live');
});

it('rejects publish-to-live when expiry is already in the past', function () {
    [$pro, $site] = makeCountdownPro('brand');

    $response = $this->actingAs($pro, 'professional')
        ->putJson("/api/professional/sites/{$site->id}/sections/countdown", [
            'publication_state' => 'live',
            'settings' => [
                'timeline' => [
                    'drop_time' => now()->subDays(5)->toIso8601String(),
                    'expiry_time' => now()->subDays(1)->toIso8601String(),
                ],
            ],
        ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('past');
});
```

Run:

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionBehaviorTest.php
```

Expected: all 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Professional/SectionVisibilityService.php app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalSectionBlockController.php tests/Feature/Countdown/CountdownSectionBehaviorTest.php
git commit -m "feat(countdown): gate publish-to-live on valid timeline"
```

---

## Task 8: Integration — recursive settings merge + public payload inclusion

**Files:**
- Modify: `tests/Feature/Countdown/CountdownSectionBehaviorTest.php` (append tests)

These tests verify that (a) partial PATCHes work correctly (existing pattern, exercised for a new nested shape) and (b) a published countdown appears in the public-site payload and a draft does not.

- [ ] **Step 1: Append failing integration tests**

Append to `tests/Feature/Countdown/CountdownSectionBehaviorTest.php`:

```php
it('merges nested countdown settings on partial PATCH', function () {
    [$pro, $site] = makeCountdownPro('brand');

    // First request — establish a baseline with timeline + live-state headline + CTA.
    $this->actingAs($pro, 'professional')
        ->putJson("/api/professional/sites/{$site->id}/sections/countdown", [
            'settings' => [
                'title' => 'The Drop',
                'timeline' => [
                    'drop_time' => '2026-05-01T20:00:00Z',
                    'expiry_time' => '2026-05-03T20:00:00Z',
                ],
                'states' => [
                    'live' => [
                        'headline' => 'Original headline',
                        'cta' => ['label' => 'Shop', 'url' => 'https://x.test'],
                    ],
                ],
            ],
        ])->assertOk();

    // Second request — only changes the live-state headline. Everything else must persist.
    $this->actingAs($pro, 'professional')
        ->putJson("/api/professional/sites/{$site->id}/sections/countdown", [
            'settings' => [
                'states' => [
                    'live' => ['headline' => 'Updated headline'],
                ],
            ],
        ])->assertOk();

    $block = Block::query()
        ->where('professional_id', $pro->id)
        ->where('block_type', 'countdown')
        ->first();

    expect(data_get($block->settings, 'title'))->toBe('The Drop');
    expect(data_get($block->settings, 'timeline.drop_time'))->toBe('2026-05-01T20:00:00Z');
    expect(data_get($block->settings, 'states.live.headline'))->toBe('Updated headline');
    expect(data_get($block->settings, 'states.live.cta.label'))->toBe('Shop');
    expect(data_get($block->settings, 'states.live.cta.url'))->toBe('https://x.test');
});

it('includes a live countdown in the public-site payload', function () {
    [$pro, $site] = makeCountdownPro('brand');

    $this->actingAs($pro, 'professional')
        ->putJson("/api/professional/sites/{$site->id}/sections/countdown", [
            'publication_state' => 'live',
            'settings' => [
                'title' => 'The Drop',
                'timeline' => [
                    'drop_time' => now()->addDays(1)->toIso8601String(),
                    'expiry_time' => now()->addDays(3)->toIso8601String(),
                ],
            ],
        ])->assertOk();

    // Fetch the public-site payload for this handle. Route pattern mirrors
    // PublicSiteController::show — hit the same handle the professional owns.
    $response = $this->getJson("/api/public/site?handle={$pro->handle}");

    $response->assertOk();
    $sections = collect($response->json('data.blocks') ?? $response->json('data.sections') ?? []);
    $countdown = $sections->firstWhere('block_type', 'countdown');

    expect($countdown)->not->toBeNull();
    expect(data_get($countdown, 'settings.title'))->toBe('The Drop');
});

it('excludes a draft countdown from the public-site payload', function () {
    [$pro, $site] = makeCountdownPro('brand');

    // Create a countdown but leave it in draft (no publication_state => default draft).
    $this->actingAs($pro, 'professional')
        ->putJson("/api/professional/sites/{$site->id}/sections/countdown", [
            'settings' => [
                'title' => 'Hidden Drop',
                'timeline' => [
                    'drop_time' => now()->addDays(1)->toIso8601String(),
                    'expiry_time' => now()->addDays(3)->toIso8601String(),
                ],
            ],
        ])->assertOk();

    $response = $this->getJson("/api/public/site?handle={$pro->handle}");

    $response->assertOk();
    $sections = collect($response->json('data.blocks') ?? $response->json('data.sections') ?? []);
    expect($sections->firstWhere('block_type', 'countdown'))->toBeNull();
});
```

**Note on the public-site route:** `PublicSiteController::show()` is domain-routed by subdomain in production (`routes/api/publicSite.php` line 23). For tests, check how other public-payload tests invoke it. If there's no `?handle=` query-param variant, use `->withHeaders(['Host' => $pro->handle . '.sidest.test'])` or the repo's existing test helper. Inspect `grep -rn "public/site\|PublicSite" tests/Feature/ | head` for a reference test and mirror its auth/host setup.

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Feature/Countdown/CountdownSectionBehaviorTest.php
```

Expected: PATCH-merge test should already PASS (existing merge logic handles nested correctly). Public-payload tests may FAIL only due to test-harness setup mismatches, not product bugs — if they fail, debug the test setup (host header, public-site route), not the implementation.

- [ ] **Step 3: Fix any test-harness issues**

If the two public-payload tests fail due to route/host resolution, update them to match the repo's existing pattern. Do NOT change any production code for these tests — the public-payload path was already exercised by the existing newsletter/gallery/etc. block types, and countdown rides on identical serialization.

- [ ] **Step 4: Run full countdown suite to confirm green**

```bash
./vendor/bin/pest tests/Feature/Countdown/
```

Expected: all tests PASS across the three files (5 config + 21 validation + 5 behavior = 31 tests).

- [ ] **Step 5: Run the full test suite to catch unintended regressions**

```bash
composer test
```

Expected: no new failures. If an unrelated test fails, stop and investigate before committing.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Countdown/CountdownSectionBehaviorTest.php
git commit -m "test(countdown): integration tests for PATCH merge and public-site payload"
```

---

## Task 9: Run Pint and verify final state

**Files:** all modified files.

- [ ] **Step 1: Run Laravel Pint to auto-fix style**

```bash
./vendor/bin/pint
```

Expected: either "No style issues found" or a small list of fixed files.

- [ ] **Step 2: Re-run the full test suite**

```bash
composer test
```

Expected: all tests pass (including the countdown suite).

- [ ] **Step 3: Commit style fixes if Pint changed anything**

```bash
git status
```

If files changed:

```bash
git add -u
git commit -m "style(countdown): apply pint formatting"
```

If nothing changed, skip this step.

- [ ] **Step 4: Summary**

Feature is complete on the backend. To verify end-to-end:
- New `countdown` section block type is registered and enabled for all three account types.
- Validation covers timeline, copy, CTAs (with scheme allowlist), and string sanitization.
- Publish-to-live is gated on a valid timeline (blocks incomplete or past-expiry countdowns).
- Recursive settings merge preserves unchanged nested fields on PATCH.
- Published countdowns appear in the public-site payload; drafts do not.

Next: Tobias implements the editor UI (timeline picker, per-state copy/CTA editors) and the public-site countdown renderer (client-side state derivation + tick). Backend is ready.

---

## Files not touched (intentionally)

- **No migration.** Countdown uses the existing `site.blocks` table and `settings` JSONB column.
- **No new route.** Uses existing `PUT /sites/{siteId}/sections/{blockType}` wildcard.
- **No new model, resource, or controller.** All existing section-block machinery applies.
- **No observer changes.** Cache invalidation on block save is already handled by the existing `Block` observer and fires for any block type.
- **No change to `AccountTypeDefaultsService::applyDefaults()`.** Countdown is intentionally NOT in `default_sections` for any account type — affiliates opt in by configuring it, they don't get an empty-by-default countdown block on signup.
