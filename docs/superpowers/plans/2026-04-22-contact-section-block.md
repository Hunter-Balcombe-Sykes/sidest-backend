# Contact Section Block Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `contact` section block to affiliate public pages. Visitors submit an enquiry form; the backend persists it to `site.enquiries`, upserts the submitter as a Customer lead, sends an email notification to the affiliate's configured inbox, and lets affiliates read/manage enquiries via dashboard endpoints.

**Architecture:** The block is a new `block_type` value in the existing `site.blocks` table (no schema changes for config). Enquiries live in a new `site.enquiries` table. Public submission uses a dedicated controller that reuses the existing `lead.log` + `throttle:leads` middleware and honeypot/timing patterns from `PublicCustomerLeadController`. The email notification runs as a queued Horizon job so the submission endpoint stays fast. Visibility gating uses the existing `SectionVisibilityService::checkVisibilityRequirements` match statement.

**Tech Stack:** Laravel 12, PostgreSQL (Supabase), Pest 4, Horizon (Redis queue), Laravel Mail.

**Spec:** [`docs/superpowers/specs/2026-04-22-contact-section-block-design.md`](../specs/2026-04-22-contact-section-block-design.md)

---

## File Structure

**New files (13):**
- `supabase/migrations/20260422040000_create_site_enquiries.sql` — creates `site.enquiries` table + indexes + RLS
- `app/Models/Core/Site/Enquiry.php` — Eloquent model with soft deletes
- `app/Http/Requests/Api/PublicSite/PublicEnquiryRequest.php` — public submission validation (honeypot, timing, subject allowlist)
- `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php` — public submission flow (validate → save → upsert customer → log → dispatch email)
- `app/Http/Controllers/Api/Professional/ProfessionalEnquiryController.php` — dashboard inbox (index, patch read, soft-delete)
- `app/Http/Resources/EnquiryResource.php` — API response shape for enquiry records
- `app/Mail/SiteEnquiryNotification.php` — Mailable with notification email body
- `app/Jobs/Notifications/SendEnquiryNotificationJob.php` — queued job wrapping the Mailable
- `resources/views/emails/enquiry-notification.blade.php` — plain-text-friendly HTML template
- `tests/Feature/Contact/ContactSectionConfigTest.php` — config registration tests
- `tests/Feature/Contact/ContactSectionValidationTest.php` — UpsertSectionBlockRequest tests for contact fields
- `tests/Feature/Contact/PublicEnquirySubmissionTest.php` — public endpoint tests (happy path, validation, bot protection, rate limit)
- `tests/Feature/Contact/ProfessionalEnquiryInboxTest.php` — dashboard endpoint tests (list, read, delete, authz)

**Modified files (5):**
- `config/sidest.php` — add `'contact'` to `section_block_types`, add `contact_subject_defaults`, append `'contact'` to each professional type's `allowed_sections`
- `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php` — add contact-specific `settings.*` rules + strip_tags
- `app/Services/Professional/SectionVisibilityService.php` — add `checkContactRequirements()`, wire into match
- `routes/api/publicSite.php` — register `POST /public/enquiry`
- `routes/api/professional.php` — register enquiry inbox routes

---

## Task 1: Register `contact` in config + section types

**Files:**
- Modify: `config/sidest.php`
- Create: `tests/Feature/Contact/ContactSectionConfigTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Contact/ContactSectionConfigTest.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Contact/ContactSectionConfigTest.php`
Expected: FAIL — `contact` not in section_block_types.

- [ ] **Step 3: Update `config/sidest.php`**

Locate the `section_block_types` array and append `'contact'`:

```php
'section_block_types' => ['gallery', 'services', 'shop', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter', 'countdown', 'contact'],
```

Add a new platform-defaults config entry at top level (just below `section_block_types`):

```php
'contact_subject_defaults' => [
    'General enquiry',
    'Booking',
    'Press',
    'Collaboration',
    'Other',
],
```

In `account_type_defaults`, append `'contact'` to each type's `allowed_sections` array:
- `influencer.allowed_sections` → add `'contact'`
- `professional.allowed_sections` → add `'contact'`
- `brand.allowed_sections` → add `'contact'`

Do NOT add `'contact'` to any `default_sections` — it's opt-in.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Contact/ContactSectionConfigTest.php`
Expected: all 6 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add config/sidest.php tests/Feature/Contact/ContactSectionConfigTest.php
git commit -m "feat(contact): register contact section block type + subject defaults"
```

---

## Task 2: Create `site.enquiries` table migration

**Files:**
- Create: `supabase/migrations/20260422040000_create_site_enquiries.sql`

- [ ] **Step 1: Create the migration file**

Create `supabase/migrations/20260422040000_create_site_enquiries.sql`:

```sql
-- Create site.enquiries table for the contact section block.
--
-- Stores visitor-submitted messages from the public contact form. Scoped to
-- professional_id (ownership) with site_id recorded for provenance. Mirrors
-- the patterns used by site.blocks: soft deletes, FK cascade on professional
-- + site deletion, RLS enabled.

BEGIN;

CREATE TABLE IF NOT EXISTS site.enquiries (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    site_id uuid NOT NULL,
    name varchar(100) NOT NULL,
    email varchar(255) NOT NULL,
    phone varchar(30),
    subject varchar(100) NOT NULL,
    message text NOT NULL,
    ip_hash varchar(64),
    user_agent varchar(500),
    read_at timestamptz,
    deleted_at timestamptz,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL
);

ALTER TABLE site.enquiries OWNER TO postgres;

ALTER TABLE ONLY site.enquiries
    ADD CONSTRAINT enquiries_pkey PRIMARY KEY (id);

ALTER TABLE ONLY site.enquiries
    ADD CONSTRAINT enquiries_professional_fk
    FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE CASCADE;

ALTER TABLE ONLY site.enquiries
    ADD CONSTRAINT enquiries_site_fk
    FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE;

-- Inbox list query: per-professional, newest first.
CREATE INDEX enquiries_professional_created_idx
    ON site.enquiries (professional_id, created_at DESC)
    WHERE deleted_at IS NULL;

-- Provenance lookups by site.
CREATE INDEX enquiries_site_idx
    ON site.enquiries (site_id)
    WHERE deleted_at IS NULL;

-- Abuse queries: show all submissions from an ip_hash.
CREATE INDEX enquiries_ip_hash_idx
    ON site.enquiries (ip_hash, created_at)
    WHERE deleted_at IS NULL;

-- RLS: gate reads/writes to the owning professional, same pattern as site.blocks.
ALTER TABLE site.enquiries ENABLE ROW LEVEL SECURITY;

CREATE POLICY enquiries_app_backend_all
    ON site.enquiries
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

GRANT SELECT, INSERT, UPDATE, DELETE ON site.enquiries TO app_backend;

COMMENT ON TABLE site.enquiries IS
    'Visitor-submitted enquiries from the contact section block. professional_id owns; site_id is provenance. read_at=null means unread.';

COMMIT;
```

- [ ] **Step 2: Run migration locally**

Run: `php artisan migrate` (this applies Supabase migrations via the project's migrate command, NOT Laravel migrations — verify no Laravel migration guard rejects the command).

If you are working against Supabase directly, run: `supabase db push` (or the equivalent project-specific command — check `composer.json` scripts and `CLAUDE.md`).

Expected: migration applies without error. `\d site.enquiries` in psql shows the new table with the three indexes.

- [ ] **Step 3: Verify schema matches**

Run this query (via `php artisan tinker` or `supabase db shell`):

```sql
\d site.enquiries
```

Expected output lists: `id`, `professional_id`, `site_id`, `name`, `email`, `phone`, `subject`, `message`, `ip_hash`, `user_agent`, `read_at`, `deleted_at`, `created_at`, `updated_at`, plus three indexes and two FKs.

- [ ] **Step 4: Commit**

```bash
git add supabase/migrations/20260422040000_create_site_enquiries.sql
git commit -m "feat(contact): add site.enquiries table for contact block submissions"
```

---

## Task 3: `Enquiry` Eloquent model

**Files:**
- Create: `app/Models/Core/Site/Enquiry.php`

- [ ] **Step 1: Write a failing test**

Create `tests/Unit/Models/EnquiryModelTest.php`:

```php
<?php

use App\Models\Core\Site\Enquiry;

it('uses the site.enquiries table', function () {
    expect((new Enquiry)->getTable())->toBe('site.enquiries');
});

it('casts read_at and timestamps to datetime', function () {
    $casts = (new Enquiry)->getCasts();

    expect($casts['read_at'])->toBe('datetime');
    expect($casts['created_at'])->toBe('datetime');
    expect($casts['updated_at'])->toBe('datetime');
    expect($casts['deleted_at'])->toBe('datetime');
});

it('uses UUID keys and soft deletes', function () {
    $model = new Enquiry;

    expect($model->incrementing)->toBeFalse();
    expect($model->getKeyType())->toBe('string');
    expect(in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model)))->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Models/EnquiryModelTest.php`
Expected: FAIL — `App\Models\Core\Site\Enquiry` does not exist.

- [ ] **Step 3: Create the model**

Create `app/Models/Core/Site/Enquiry.php`:

```php
<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// V2: A visitor-submitted enquiry from a site's contact section block. read_at=null means unread.
class Enquiry extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'site.enquiries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'site_id',
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'ip_hash',
        'user_agent',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Models/EnquiryModelTest.php`
Expected: all 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Core/Site/Enquiry.php tests/Unit/Models/EnquiryModelTest.php
git commit -m "feat(contact): add Enquiry model"
```

---

## Task 4: Contact-specific rules in `UpsertSectionBlockRequest`

**Files:**
- Modify: `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php`
- Create: `tests/Feature/Contact/ContactSectionValidationTest.php`

This uses the direct Form Request validation harness — no HTTP, no DB — matching the pattern from `tests/Feature/Newsletter/NewsletterSectionValidationTest.php` and `tests/Feature/Countdown/CountdownSectionValidationTest.php`.

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Contact/ContactSectionValidationTest.php`:

```php
<?php

use App\Http\Requests\Api\Professional\Site\UpsertSectionBlockRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Direct form-request validation harness — no DB, no HTTP stack.
 * Same pattern as Newsletter + Countdown validation tests.
 */
function validateContactUpsert(array $payload): array
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

it('accepts a contact block with no settings (draft with no config yet)', function () {
    $result = validateContactUpsert([
        'block_type' => 'contact',
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts a contact block with full settings', function () {
    $result = validateContactUpsert([
        'block_type' => 'contact',
        'settings' => [
            'headline' => 'Get in touch',
            'description' => 'Fill out the form and I will get back to you.',
            'notification_email' => 'hello@mybrand.com',
            'cta_label' => 'Send message',
            'subject_options' => ['Wholesale', 'Stockist'],
        ],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['settings']['notification_email'])->toBe('hello@mybrand.com');
});

it('rejects invalid notification_email', function () {
    $result = validateContactUpsert([
        'block_type' => 'contact',
        'settings' => ['notification_email' => 'not-an-email'],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.notification_email');
});

it('caps subject_options at 10 items', function () {
    $result = validateContactUpsert([
        'block_type' => 'contact',
        'settings' => [
            'subject_options' => array_fill(0, 11, 'Option'),
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.subject_options');
});

it('rejects a subject option over 60 chars', function () {
    $result = validateContactUpsert([
        'block_type' => 'contact',
        'settings' => [
            'subject_options' => [str_repeat('x', 61)],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.subject_options.0');
});

it('rejects duplicate subject options', function () {
    $result = validateContactUpsert([
        'block_type' => 'contact',
        'settings' => [
            'subject_options' => ['Press', 'Press'],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
});

it('strips HTML tags from headline and description (defense-in-depth)', function () {
    $result = validateContactUpsert([
        'block_type' => 'contact',
        'settings' => [
            'headline' => '<script>alert(1)</script>Hi',
            'description' => '<b>bold</b> text',
            'notification_email' => 'a@b.com',
        ],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['settings']['headline'])->toBe('Hi');
    expect($result['data']['settings']['description'])->toBe('bold text');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Contact/ContactSectionValidationTest.php`
Expected: FAIL — validation rules for contact fields don't exist yet.

- [ ] **Step 3: Extend the Form Request**

In `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php`, add contact field rules to the `rules()` array (after the newsletter rules):

```php
// Contact section — configurable copy, notification inbox, custom subject options.
// Merged with platform defaults at render/validation time. notification_email
// is nullable in the request but required to publish (enforced in
// SectionVisibilityService::checkContactRequirements).
'settings.notification_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
'settings.subject_options' => ['sometimes', 'nullable', 'array', 'max:10'],
'settings.subject_options.*' => ['string', 'max:60', 'distinct'],
```

Note: `settings.headline`, `settings.description`, and `settings.cta_label` already exist from the newsletter block and are reused verbatim — do NOT duplicate them.

In `prepareForValidation()`, the existing `foreach (['headline', 'description', 'cta_label'] as $key)` loop already strip_tags these shared fields. No change needed unless you want to add more fields.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Contact/ContactSectionValidationTest.php`
Expected: all 5 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php tests/Feature/Contact/ContactSectionValidationTest.php
git commit -m "feat(contact): validate contact block settings (email, subject options)"
```

---

## Task 5: Publish-gate requirement via `SectionVisibilityService`

**Files:**
- Modify: `app/Services/Professional/SectionVisibilityService.php`
- Create: `tests/Feature/Contact/ContactSectionBehaviorTest.php`

This uses the `SectionVisibilityTestCase::boot()` SQLite-in-memory harness + raw DB inserts — the exact pattern used by `tests/Feature/Countdown/CountdownSectionBehaviorTest.php`. Read that file before starting so your test setup matches.

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Contact/ContactSectionBehaviorTest.php`:

```php
<?php

use App\Services\Professional\SectionVisibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureFlags\SectionVisibilityTestCase;

beforeEach(function () {
    SectionVisibilityTestCase::boot();
});

function seedContactProAndSite(): array
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'contact-pro',
        'display_name' => 'Contact Pro',
        'primary_email' => 'contact@example.com',
        'status' => 'active',
    ]);

    return [$proId, $siteId];
}

function seedContactBlock(string $proId, string $siteId, array $settings = [], bool $isActive = false): void
{
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'site_id' => $siteId,
        'block_group' => 'sections',
        'block_type' => 'contact',
        'settings' => json_encode($settings),
        'is_enabled' => 1,
        'is_active' => $isActive ? 1 : 0,
    ]);
}

it('rejects publishing a contact block with no stored block', function () {
    [$proId, $siteId] = seedContactProAndSite();

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'contact');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('notification email');
});

it('rejects publishing a contact block with empty settings', function () {
    [$proId, $siteId] = seedContactProAndSite();
    seedContactBlock($proId, $siteId, []);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'contact');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('notification email');
});

it('rejects publishing a contact block with a blank notification_email', function () {
    [$proId, $siteId] = seedContactProAndSite();
    seedContactBlock($proId, $siteId, ['notification_email' => '   ']);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'contact');

    expect($canBeVisible)->toBeFalse();
});

it('rejects publishing a contact block with an invalid notification_email', function () {
    [$proId, $siteId] = seedContactProAndSite();
    seedContactBlock($proId, $siteId, ['notification_email' => 'not-an-email']);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'contact');

    expect($canBeVisible)->toBeFalse();
});

it('allows publishing a contact block with a valid notification_email', function () {
    [$proId, $siteId] = seedContactProAndSite();
    seedContactBlock($proId, $siteId, ['notification_email' => 'hello@mybrand.com']);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'contact');

    expect($canBeVisible)->toBeTrue();
    expect($reason)->toBeNull();
});

it('honours pendingSettings on the first-publish path (block stored without email, incoming payload supplies it)', function () {
    [$proId, $siteId] = seedContactProAndSite();
    seedContactBlock($proId, $siteId, []);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements(
            $proId,
            $siteId,
            'contact',
            ['notification_email' => 'hello@mybrand.com'],
        );

    expect($canBeVisible)->toBeTrue();
});
```

**Important:** `SectionVisibilityTestCase::boot()` creates a minimal schema — look at its source and confirm it already creates `site.blocks`. If not, extend it to include `site.blocks` with the columns this test uses (`id`, `professional_id`, `site_id`, `block_group`, `block_type`, `settings`, `is_active`, `is_enabled`, `deleted_at`). The countdown test file exercises the same table, so this should already be in place — if it isn't, note it in the commit.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Contact/ContactSectionPublishGateTest.php`
Expected: FAIL — `checkContactRequirements` does not exist.

- [ ] **Step 3: Extend `SectionVisibilityService`**

In `app/Services/Professional/SectionVisibilityService.php`:

Update the `match` statement in `checkVisibilityRequirements()` to include `'contact'`:

```php
return match ($blockType) {
    'gallery' => $this->checkGalleryRequirements($siteId),
    'booking' => $this->checkBookingRequirements($professionalId),
    'services' => $this->checkServicesRequirements($professionalId),
    'documents' => $this->checkDocumentsRequirements($siteId),
    'countdown' => $this->checkCountdownRequirements($professionalId, $siteId, $pendingSettings),
    'contact' => $this->checkContactRequirements($professionalId, $siteId, $pendingSettings),
    default => [true, null],
};
```

Add the new private method at the bottom of the class:

```php
/**
 * A contact block is publishable only when it has a valid notification_email.
 * The email is part of the block's own settings (like countdown's timeline) —
 * so the controller passes the incoming payload through as $pendingSettings
 * to cover the first-time-publish path where the field and publication_state=live
 * arrive in the same request.
 *
 * @param  array<string, mixed>|null  $pendingSettings
 * @return array{0: bool, 1: ?string}
 */
private function checkContactRequirements(string $professionalId, string $siteId, ?array $pendingSettings = null): array
{
    $block = Block::query()
        ->where('professional_id', $professionalId)
        ->where('site_id', $siteId)
        ->where('block_group', 'sections')
        ->where('block_type', 'contact')
        ->first();

    $stored = $block && is_array($block->settings) ? $block->settings : [];
    $settings = $pendingSettings !== null
        ? array_replace_recursive($stored, $pendingSettings)
        : $stored;

    $email = data_get($settings, 'notification_email');
    $email = is_string($email) ? trim($email) : '';

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return [false, 'Contact section requires a notification email before it can go live.'];
    }

    return [true, null];
}
```

Then update the controller site to pass `pendingSettings` through for contact, mirroring countdown. In `ProfessionalSectionBlockController::upsert()`, find the `checkVisibilityRequirements` call (around line 98) and confirm it already passes incoming settings — if it doesn't pass `pendingSettings` for generic block types, wire it up exactly as countdown does (the existing code path for countdown already handles this, and contact follows the same pattern).

If the existing controller only passes `pendingSettings` for countdown, update the call so contact also receives `$data['settings'] ?? null`:

```php
[$canBeVisible, $reason] = $this->visibilityService->checkVisibilityRequirements(
    (string) $pro->id,
    (string) $site->id,
    $blockType,
    in_array($blockType, ['countdown', 'contact'], true) ? ($data['settings'] ?? null) : null,
);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Contact/ContactSectionBehaviorTest.php`
Expected: all 6 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Professional/SectionVisibilityService.php app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalSectionBlockController.php tests/Feature/Contact/ContactSectionBehaviorTest.php
git commit -m "feat(contact): gate publish on notification_email presence"
```

---

## Task 6: `PublicEnquiryRequest` validation

**Files:**
- Create: `app/Http/Requests/Api/PublicSite/PublicEnquiryRequest.php`

- [ ] **Step 1: Create the request class**

Create `app/Http/Requests/Api/PublicSite/PublicEnquiryRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;

// V2: Validates public contact form submissions — name, email, phone, subject (allowlist checked in controller), message, with honeypot + timing bot protection.
class PublicEnquiryRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim(strip_tags((string) $this->name)) : $this->name,
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'phone' => is_string($this->phone) ? trim(strip_tags((string) $this->phone)) : $this->phone,
            'subject' => is_string($this->subject) ? trim($this->subject) : $this->subject,
            'message' => is_string($this->message) ? trim(strip_tags((string) $this->message)) : $this->message,
            'website' => is_string($this->website) ? trim($this->website) : $this->website,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'subject' => ['required', 'string', 'max:100'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],

            // Bot protection (same pattern as PublicCustomerLeadRequest)
            'website' => ['nullable', 'string', 'max:255'],
            'form_started_at_ms' => ['required', 'integer', 'min:0'],
        ];
    }
}
```

- [ ] **Step 2: Commit** (no test yet — exercised end-to-end in Task 9)

```bash
git add app/Http/Requests/Api/PublicSite/PublicEnquiryRequest.php
git commit -m "feat(contact): add PublicEnquiryRequest validation"
```

---

## Task 7: `SiteEnquiryNotification` Mailable + template

**Files:**
- Create: `app/Mail/SiteEnquiryNotification.php`
- Create: `resources/views/emails/enquiry-notification.blade.php`

- [ ] **Step 1: Create the email template**

Create `resources/views/emails/enquiry-notification.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>New enquiry</title></head>
<body style="font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; font-size: 14px; color: #111; line-height: 1.5;">
    <h2 style="margin: 0 0 16px;">New enquiry from {{ $enquiry->name }}</h2>

    <p style="margin: 0 0 8px;"><strong>Subject:</strong> {{ $enquiry->subject }}</p>
    <p style="margin: 0 0 8px;"><strong>From:</strong> {{ $enquiry->name }} &lt;{{ $enquiry->email }}&gt;</p>

    @if ($enquiry->phone)
        <p style="margin: 0 0 8px;"><strong>Phone:</strong> {{ $enquiry->phone }}</p>
    @endif

    <p style="margin: 0 0 8px;"><strong>Submitted:</strong> {{ $enquiry->created_at->format('j M Y H:i') }} UTC</p>

    <hr style="border: none; border-top: 1px solid #ddd; margin: 16px 0;">

    <p style="margin: 0 0 8px;"><strong>Message:</strong></p>
    <p style="white-space: pre-wrap; margin: 0 0 16px;">{{ $enquiry->message }}</p>

    <hr style="border: none; border-top: 1px solid #ddd; margin: 16px 0;">

    <p style="margin: 0; color: #666; font-size: 12px;">
        <a href="{{ $dashboardUrl }}" style="color: #0066cc;">View in your dashboard</a>
    </p>
</body>
</html>
```

- [ ] **Step 2: Create the Mailable**

Create `app/Mail/SiteEnquiryNotification.php`:

```php
<?php

namespace App\Mail;

use App\Models\Core\Site\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SiteEnquiryNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Enquiry $enquiry,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New enquiry from {$this->enquiry->name} — {$this->enquiry->subject}",
        );
    }

    public function content(): Content
    {
        $dashboardUrl = rtrim((string) config('app.dashboard_url', config('app.url')), '/').'/enquiries';

        return new Content(
            view: 'emails.enquiry-notification',
            with: [
                'enquiry' => $this->enquiry,
                'dashboardUrl' => $dashboardUrl,
            ],
        );
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Mail/SiteEnquiryNotification.php resources/views/emails/enquiry-notification.blade.php
git commit -m "feat(contact): add SiteEnquiryNotification Mailable"
```

---

## Task 8: `SendEnquiryNotificationJob` queued job

**Files:**
- Create: `app/Jobs/Notifications/SendEnquiryNotificationJob.php`

- [ ] **Step 1: Create the job**

Create `app/Jobs/Notifications/SendEnquiryNotificationJob.php`:

```php
<?php

namespace App\Jobs\Notifications;

use App\Mail\SiteEnquiryNotification;
use App\Models\Core\Site\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// V2: Sends the contact-form notification email to the affiliate's configured inbox after an enquiry is saved.
class SendEnquiryNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $enquiryId,
        public readonly string $notificationEmail,
    ) {}

    public function handle(): void
    {
        $enquiry = Enquiry::query()->find($this->enquiryId);

        if (! $enquiry) {
            Log::warning('SendEnquiryNotificationJob: enquiry not found', [
                'enquiry_id' => $this->enquiryId,
            ]);

            return;
        }

        Mail::to($this->notificationEmail)->send(new SiteEnquiryNotification($enquiry));
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Jobs/Notifications/SendEnquiryNotificationJob.php
git commit -m "feat(contact): add SendEnquiryNotificationJob queued job"
```

---

## Task 9: `PublicEnquiryController` + public route

**Files:**
- Create: `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php`
- Modify: `routes/api/publicSite.php`, `routes/api.php`
- Create: `tests/Feature/Contact/PublicEnquirySubmissionTest.php`

**Before you start**, read `tests/Feature/PublicSite/PublicWaitlistControllerTest.php` for the public-endpoint HTTP test pattern. It uses `setupWaitlistSchema()` + raw `DB::connection('pgsql')->table(...)` reads/inserts + `$this->postJson(...)` — the same shape your test will follow.

There is no existing `setupSiteAndBlocksSchema()` helper. You will need to either (a) extend `SectionVisibilityTestCase::boot()` to also create `site.sites`, or (b) write a local helper function at the top of the test file that inserts the schema into the pgsql connection. Look at `PublicWaitlistControllerTest` + `setupWaitlistSchema()` (in `tests/Pest.php` or `tests/Unit/TestCase.php` — grep for it) for the pattern. Follow that exactly; don't invent a new harness.

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Contact/PublicEnquirySubmissionTest.php`. The skeleton below shows the shape — fill in the local schema helper to match the existing `setupWaitlistSchema()` style (raw `CREATE TABLE` statements on the pgsql connection for `core.professionals`, `core.customers`, `site.sites`, `site.blocks`, `site.enquiries`, `analytics.lead_submissions`):

```php
<?php

use App\Jobs\Notifications\SendEnquiryNotificationJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['sidest.throttle.enabled' => false]); // disable rate limiter for unit-style HTTP tests

    setupContactTestSchema(); // see helper below — follow setupWaitlistSchema() shape
});

function setupContactTestSchema(): void
{
    // Boot the same SQLite-in-memory pgsql connection used by PublicWaitlistControllerTest
    // and create the minimal schema: core.professionals, core.customers, site.sites,
    // site.blocks, site.enquiries, analytics.lead_submissions.
    //
    // Copy the pattern from the existing setupWaitlistSchema() helper (grep the tests
    // directory for it). Key points:
    //   - Attach schemas as SQLite databases (ATTACH DATABASE ':memory:' AS core, etc.)
    //   - Only include columns this test needs (don't clone production schema)
    //   - Set integer/text types that SQLite accepts but Eloquent can still cast
    //
    // ...
}

function seedPublishedContactSite(string $subdomain = 'testpro'): array
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => $subdomain,
        'display_name' => 'Test Pro',
        'primary_email' => 'test@example.com',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => $subdomain,
        'is_published' => 1,
    ]);

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'site_id' => $siteId,
        'block_group' => 'sections',
        'block_type' => 'contact',
        'is_active' => 1,
        'is_enabled' => 1,
        'settings' => json_encode([
            'notification_email' => 'hello@mybrand.com',
            'subject_options' => ['Wholesale'],
        ]),
    ]);

    return [$proId, $siteId];
}

function validEnquiryPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sarah Jones',
        'email' => 'sarah@example.com',
        'phone' => '+44 7700 900000',
        'subject' => 'Wholesale',
        'message' => 'Hi, I would love to stock your products in my shop.',
        'website' => '',
        'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
    ], $overrides);
}

it('accepts a valid submission and saves a site.enquiries row', function () {
    [$proId, $siteId] = seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk()->assertJson(['ok' => true]);

    $row = DB::connection('pgsql')->table('site.enquiries')->first();
    expect($row)->not->toBeNull();
    expect($row->name)->toBe('Sarah Jones');
    expect($row->email)->toBe('sarah@example.com');
    expect($row->subject)->toBe('Wholesale');
    expect($row->professional_id)->toBe($proId);
    expect($row->site_id)->toBe($siteId);
});

it('upserts submitter as a Customer with source=enquiry', function () {
    [$proId] = seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();

    $customer = DB::connection('pgsql')->table('core.customers')->first();
    expect($customer)->not->toBeNull();
    expect($customer->email)->toBe('sarah@example.com');
    expect($customer->source)->toBe('enquiry');
    expect($customer->professional_id)->toBe($proId);
});

it('dispatches SendEnquiryNotificationJob with the configured inbox', function () {
    seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();

    Bus::assertDispatched(SendEnquiryNotificationJob::class, fn ($job) => $job->notificationEmail === 'hello@mybrand.com');
});

it('rejects a subject not in the merged options list', function () {
    seedPublishedContactSite();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(['subject' => 'NotAnOption']), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertStatus(422);
});

it('accepts a platform-default subject even if the affiliate never listed it', function () {
    seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(['subject' => 'General enquiry']), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();
});

it('rejects a message shorter than 10 chars', function () {
    seedPublishedContactSite();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(['message' => 'too short']), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertStatus(422);
});

it('honeypot filled returns 200, saves nothing, and logs outcome=honeypot', function () {
    seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(['website' => 'http://spam.com']), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();

    expect(DB::connection('pgsql')->table('site.enquiries')->count())->toBe(0);
    Bus::assertNotDispatched(SendEnquiryNotificationJob::class);

    $lead = DB::connection('pgsql')->table('analytics.lead_submissions')->first();
    expect($lead?->outcome)->toBe('honeypot');
});

it('too-fast submission is rejected with outcome=too_fast', function () {
    seedPublishedContactSite();

    $this->postJson('/api/public/enquiry', validEnquiryPayload([
        'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 100,
    ]), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertStatus(422);

    expect(DB::connection('pgsql')->table('site.enquiries')->count())->toBe(0);

    $lead = DB::connection('pgsql')->table('analytics.lead_submissions')->first();
    expect($lead?->outcome)->toBe('too_fast');
});

it('rejects submission to a site without an active contact block', function () {
    seedPublishedContactSite();
    DB::connection('pgsql')->table('site.blocks')->where('block_type', 'contact')->update(['is_active' => 0]);

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertStatus(422);
});

it('strips HTML tags from name and message', function () {
    seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload([
        'name' => '<b>Sarah</b>',
        'message' => '<script>bad()</script>Please call me about wholesale.',
    ]), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();

    $row = DB::connection('pgsql')->table('site.enquiries')->first();
    expect($row->name)->toBe('Sarah');
    expect($row->message)->not->toContain('<script>');
    expect($row->message)->toContain('Please call me');
});

it('logs outcome=created on success', function () {
    seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();

    $lead = DB::connection('pgsql')->table('analytics.lead_submissions')->first();
    expect($lead?->outcome)->toBe('created');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Contact/PublicEnquirySubmissionTest.php`
Expected: FAIL — route `/api/public/enquiry` does not exist.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php`:

```php
<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Http\Requests\Api\PublicSite\PublicEnquiryRequest;
use App\Jobs\Notifications\SendEnquiryNotificationJob;
use App\Models\Analytics\LeadSubmission;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Public\PublicSiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Handles public contact form submissions. Saves enquiry, upserts submitter as Customer lead, dispatches notification email.
class PublicEnquiryController extends ApiController
{
    use HashesClientData;
    use ResolvesSubdomainFromHost;

    public function submit(PublicEnquiryRequest $request, PublicSiteResolver $resolver): JsonResponse
    {
        $data = $request->validated();

        $subdomain = $this->resolveSiteSubdomain($request);
        $startedMs = $data['form_started_at_ms'] ?? null;

        // 1) Honeypot: pretend success but record the abuse attempt.
        $honeypot = $data['website'] ?? null;
        if (is_string($honeypot) && trim($honeypot) !== '') {
            $this->logLead($request, $subdomain, null, null, 'honeypot', $startedMs);

            return $this->success(['ok' => true]);
        }

        // 2) Timing check: reject fires that are implausibly fast or old.
        if (is_int($startedMs)) {
            $nowMs = (int) floor(microtime(true) * 1000);
            $delta = $nowMs - $startedMs;
            $minMs = (int) config('sidest.form_timing.min_ms', 2500);
            $maxMs = (int) config('sidest.form_timing.max_ms', 12 * 60 * 60 * 1000);

            if ($delta < $minMs || $delta > $maxMs) {
                $this->logLead($request, $subdomain, null, null, 'too_fast', $startedMs);

                return $this->error('Invalid submission.', 422);
            }
        }

        if (! $subdomain) {
            $this->logLead($request, null, null, null, 'no_subdomain', $startedMs);

            return $this->error('Could not determine site from URL.', 400);
        }

        $site = $resolver->resolvePublishedSite($subdomain);
        if (! $site || ! $site->professional_id) {
            $this->logLead($request, $subdomain, $site?->id, null, 'site_not_found', $startedMs);

            return $this->error('Site not found.', 404);
        }

        // 3) Contact block must be active on this site.
        $block = Block::query()
            ->where('site_id', $site->id)
            ->where('block_group', 'sections')
            ->where('block_type', 'contact')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $block) {
            return $this->error('This site is not accepting enquiries.', 422);
        }

        // 4) Validate subject against merged options (platform defaults + affiliate additions).
        $defaults = (array) config('sidest.contact_subject_defaults', []);
        $custom = is_array(data_get($block->settings, 'subject_options')) ? data_get($block->settings, 'subject_options') : [];
        $mergedOptions = array_values(array_unique(array_merge($defaults, $custom)));

        if (! in_array($data['subject'], $mergedOptions, true)) {
            return $this->error('Invalid subject.', 422);
        }

        // 5) Save the enquiry.
        $enquiry = Enquiry::query()->create([
            'professional_id' => $site->professional_id,
            'site_id' => $site->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'ip_hash' => $this->hashIp($request->ip()),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);

        // 6) Upsert submitter as Customer lead.
        $this->upsertEnquiryCustomer((string) $site->professional_id, $data['email'], $data['name'], $data['phone'] ?? null);

        // 7) Log unified lead analytics.
        $this->logLead($request, $subdomain, $site->id, (string) $site->professional_id, 'created', $startedMs);

        // 8) Dispatch notification email (only if settings.notification_email is present).
        $notificationEmail = data_get($block->settings, 'notification_email');
        if (is_string($notificationEmail) && trim($notificationEmail) !== '') {
            SendEnquiryNotificationJob::dispatch((string) $enquiry->id, trim($notificationEmail));
        }

        return $this->success(['ok' => true]);
    }

    private function resolveSiteSubdomain(Request $request): ?string
    {
        $fromHeader = trim((string) $request->header('X-Site-Subdomain', ''));
        if ($fromHeader !== '') {
            return strtolower($fromHeader);
        }

        foreach (['subdomain', 'slug'] as $key) {
            $fromQuery = trim((string) $request->query($key, ''));
            if ($fromQuery !== '') {
                return strtolower($fromQuery);
            }
            $fromInput = trim((string) $request->input($key, ''));
            if ($fromInput !== '') {
                return strtolower($fromInput);
            }
        }

        $fromHost = $this->resolveSubdomainFromHost($request);

        return $fromHost ? strtolower($fromHost) : null;
    }

    private function upsertEnquiryCustomer(string $professionalId, string $email, ?string $fullName, ?string $phone): void
    {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            return;
        }

        $existing = Customer::query()
            ->withTrashed()
            ->where('professional_id', $professionalId)
            ->whereRaw('lower(email) = ?', [$normalizedEmail])
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            if ($fullName && trim((string) ($existing->full_name ?? '')) === '') {
                $existing->full_name = $fullName;
            }

            if ($phone && trim((string) ($existing->phone ?? '')) === '') {
                $existing->phone = $phone;
            }

            if (($existing->source ?? '') === '') {
                $existing->source = 'enquiry';
            }

            $existing->save();

            return;
        }

        $customer = new Customer;
        $customer->professional_id = $professionalId;
        $customer->email = $normalizedEmail;
        $customer->full_name = $fullName ?: null;
        $customer->phone = $phone ?: null;
        $customer->source = 'enquiry';
        $customer->save();
    }

    private function logLead(
        Request $request,
        ?string $subdomain,
        ?string $siteId,
        ?string $professionalId,
        string $outcome,
        ?int $formStartedAtMs,
    ): void {
        LeadSubmission::query()->create([
            'occurred_at' => now(),
            'subdomain' => $subdomain,
            'site_id' => $siteId,
            'professional_id' => $professionalId,
            'customer_id' => null,
            'ip_hash' => $this->hashIp($request->ip()),
            'user_agent' => $request->userAgent(),
            'referrer' => $request->headers->get('referer'),
            'outcome' => $outcome,
            'form_started_at_ms' => $formStartedAtMs,
        ]);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/api/publicSite.php`, inside the `/public/*` group (the same group that contains `/subscribe` and `/customers`), add:

```php
use App\Http\Controllers\Api\PublicSite\PublicEnquiryController;

Route::post('/enquiry', [PublicEnquiryController::class, 'submit'])
    ->middleware(['lead.log', 'throttle:leads']);
```

Also register a bare-path fallback in `routes/api.php` that matches the existing `POST /public/customers` at line 144 (which exists alongside the group-prefixed `/customers` route — this dual registration handles different frontend routing conventions):

```php
Route::post('/public/enquiry', [PublicEnquiryController::class, 'submit'])
    ->middleware(['lead.log', 'throttle:leads']);
```

Add the `use` statement at the top of `routes/api.php` to match the existing import style.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Contact/PublicEnquirySubmissionTest.php`
Expected: all 11 tests PASS.

If any test fails with "route not found" — the route file isn't being picked up; double-check the registration. If `setupContactTestSchema()` throws because `analytics.lead_submissions` doesn't exist, extend the helper to create that table; look at how `setupWaitlistSchema()` handles multi-schema creation.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php routes/api/publicSite.php routes/api.php tests/Feature/Contact/PublicEnquirySubmissionTest.php
git commit -m "feat(contact): add public enquiry submission endpoint"
```

---

## Task 10: `EnquiryResource`

**Files:**
- Create: `app/Http/Resources/EnquiryResource.php`

- [ ] **Step 1: Create the resource**

Create `app/Http/Resources/EnquiryResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnquiryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'subject' => $this->subject,
            'message' => $this->message,
            'read_at' => optional($this->read_at)->toIso8601String(),
            'is_read' => $this->read_at !== null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Resources/EnquiryResource.php
git commit -m "feat(contact): add EnquiryResource for API responses"
```

---

## Task 11: `ProfessionalEnquiryController` — inbox endpoints

**Files:**
- Create: `app/Http/Controllers/Api/Professional/ProfessionalEnquiryController.php`
- Modify: `routes/api/professional.php`
- Create: `tests/Unit/ProfessionalEnquiryControllerTest.php`

**Testing approach note:** the authenticated-professional endpoints resolve the current Professional from request attributes populated by the `current.pro` middleware (see `app/Http/Controllers/Concerns/ResolveCurrentProfessional.php`). Before writing tests, grep the tests directory for another controller that calls `$this->currentProfessional($request)` and find how its test harness populates the request attribute. Match that pattern. If no such pattern exists, fall back to a unit-style test that invokes the controller directly with a Professional model instance preloaded into a manually constructed `Request` via `$request->attributes->set('professional', $pro)`.

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/ProfessionalEnquiryControllerTest.php`. Use this unit-style pattern that bypasses the `current.pro` middleware by populating `$request->attributes` directly:

```php
<?php

use App\Http\Controllers\Api\Professional\ProfessionalEnquiryController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Enquiry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupContactInboxSchema(); // minimal schema: core.professionals + site.enquiries
});

function setupContactInboxSchema(): void
{
    // Mirror setupWaitlistSchema() — configure the pgsql connection to use SQLite
    // in-memory and CREATE TABLE for core.professionals + site.enquiries with just
    // the columns this test exercises.
    //
    // If SectionVisibilityTestCase::boot() already handles core.professionals,
    // extend it rather than duplicating — but site.enquiries likely needs to be
    // added here the first time.
}

function makeInboxProfessional(): Professional
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'inbox-'.substr($id, 0, 8),
        'display_name' => 'Inbox Pro',
        'primary_email' => 'inbox@example.com',
        'professional_type' => 'professional',
        'status' => 'active',
    ]);

    return Professional::query()->find($id);
}

function seedInboxEnquiry(string $proId, string $siteId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.enquiries')->insert(array_merge([
        'id' => $id,
        'professional_id' => $proId,
        'site_id' => $siteId,
        'name' => 'Sarah',
        'email' => 's@e.com',
        'subject' => 'Press',
        'message' => 'A ten-char message here.',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));

    return $id;
}

function requestAs(Professional $pro, string $method = 'GET', array $data = []): Request
{
    $request = Request::create('/api/professional/enquiries', $method, $data);
    $request->attributes->set('professional', $pro);
    $request->setJson(new \Symfony\Component\HttpFoundation\ParameterBag($data));

    return $request;
}

it('lists the current professional enquiries newest first', function () {
    $pro = makeInboxProfessional();
    $siteId = (string) Str::uuid();

    seedInboxEnquiry($pro->id, $siteId, ['name' => 'Older', 'created_at' => now()->subDay()->toDateTimeString()]);
    seedInboxEnquiry($pro->id, $siteId, ['name' => 'Newer']);

    $response = app(ProfessionalEnquiryController::class)->index(requestAs($pro));
    $body = $response->getData(true);

    expect($body['data'][0]['name'])->toBe('Newer');
    expect($body['data'][1]['name'])->toBe('Older');
});

it('does not leak other professionals enquiries', function () {
    $me = makeInboxProfessional();
    $otherId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $otherId, 'handle' => 'other', 'display_name' => 'Other',
        'primary_email' => 'other@e.com', 'professional_type' => 'professional', 'status' => 'active',
    ]);
    seedInboxEnquiry($otherId, (string) Str::uuid(), ['name' => 'Not mine']);

    $response = app(ProfessionalEnquiryController::class)->index(requestAs($me));
    $body = $response->getData(true);

    expect($body['data'])->toHaveCount(0);
});

it('marks an enquiry as read', function () {
    $pro = makeInboxProfessional();
    $enquiryId = seedInboxEnquiry($pro->id, (string) Str::uuid());

    app(ProfessionalEnquiryController::class)->update(requestAs($pro, 'PATCH', ['read' => true]), $enquiryId);

    $fresh = Enquiry::query()->find($enquiryId);
    expect($fresh->read_at)->not->toBeNull();
});

it('marks an enquiry as unread', function () {
    $pro = makeInboxProfessional();
    $enquiryId = seedInboxEnquiry($pro->id, (string) Str::uuid(), ['read_at' => now()->toDateTimeString()]);

    app(ProfessionalEnquiryController::class)->update(requestAs($pro, 'PATCH', ['read' => false]), $enquiryId);

    $fresh = Enquiry::query()->find($enquiryId);
    expect($fresh->read_at)->toBeNull();
});

it('soft-deletes an enquiry', function () {
    $pro = makeInboxProfessional();
    $enquiryId = seedInboxEnquiry($pro->id, (string) Str::uuid());

    app(ProfessionalEnquiryController::class)->destroy(requestAs($pro, 'DELETE'), $enquiryId);

    expect(Enquiry::query()->find($enquiryId))->toBeNull();
    expect(Enquiry::withTrashed()->find($enquiryId))->not->toBeNull();
});

it('returns 404 when acting on another professionals enquiry', function () {
    $me = makeInboxProfessional();
    $otherId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $otherId, 'handle' => 'other2', 'display_name' => 'Other',
        'primary_email' => 'other2@e.com', 'professional_type' => 'professional', 'status' => 'active',
    ]);
    $enquiryId = seedInboxEnquiry($otherId, (string) Str::uuid());

    $response = app(ProfessionalEnquiryController::class)->update(requestAs($me, 'PATCH', ['read' => true]), $enquiryId);
    expect($response->getStatusCode())->toBe(404);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/ProfessionalEnquiryControllerTest.php`
Expected: FAIL — `ProfessionalEnquiryController` does not exist.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Professional/ProfessionalEnquiryController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Resources\EnquiryResource;
use App\Models\Core\Site\Enquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Dashboard inbox for visitor-submitted enquiries. Read-only list + mark read/unread + soft-delete.
class ProfessionalEnquiryController extends ApiController
{
    use ResolveCurrentProfessional;

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $page = Enquiry::query()
            ->where('professional_id', $pro->id)
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 20));

        return $this->success([
            'data' => EnquiryResource::collection($page->items())->toArray($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $enquiry = Enquiry::query()
            ->where('professional_id', $pro->id)
            ->find($id);

        if (! $enquiry) {
            return $this->error('Enquiry not found.', 404);
        }

        $request->validate([
            'read' => ['required', 'boolean'],
        ]);

        $enquiry->read_at = $request->boolean('read') ? now() : null;
        $enquiry->save();

        return $this->success([
            'enquiry' => (new EnquiryResource($enquiry))->toArray($request),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $enquiry = Enquiry::query()
            ->where('professional_id', $pro->id)
            ->find($id);

        if (! $enquiry) {
            return $this->error('Enquiry not found.', 404);
        }

        $enquiry->delete();

        return $this->success(['ok' => true]);
    }
}
```

- [ ] **Step 4: Register routes**

In `routes/api/professional.php`, register:

```php
use App\Http\Controllers\Api\Professional\ProfessionalEnquiryController;

Route::get('/enquiries', [ProfessionalEnquiryController::class, 'index']);
Route::patch('/enquiries/{id}', [ProfessionalEnquiryController::class, 'update']);
Route::delete('/enquiries/{id}', [ProfessionalEnquiryController::class, 'destroy']);
```

Place these inside the existing authenticated professional group (look for the `Route::middleware([...])` block that wraps other `/api/professional/*` routes).

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/ProfessionalEnquiryControllerTest.php`
Expected: all 6 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Professional/ProfessionalEnquiryController.php routes/api/professional.php tests/Unit/ProfessionalEnquiryControllerTest.php
git commit -m "feat(contact): add professional enquiry inbox endpoints"
```

---

## Task 12: End-to-end verification

- [ ] **Step 1: Run the full test suite**

Run: `composer test`
Expected: all tests pass. If any existing tests fail due to the new `section_block_types` entry, update them to include `'contact'`.

- [ ] **Step 2: Manual smoke test via curl against the dev server**

Start the local dev stack: `composer dev`

Then, from a separate shell, pick an existing published site from the local database (via tinker: `\App\Models\Core\Site\Site::query()->where('is_published', true)->first()`). Configure its contact block via the dashboard editor (or insert a block row directly via SQL), then submit:

```bash
curl -X POST http://localhost:8000/api/public/enquiry \
  -H "Content-Type: application/json" \
  -H "X-Site-Subdomain: <the site subdomain>" \
  -d '{
    "name": "Smoke Test",
    "email": "smoke@test.com",
    "subject": "General enquiry",
    "message": "This is a smoke test submission.",
    "website": "",
    "form_started_at_ms": '"$(( $(date +%s%3N) - 5000 ))"'
  }'
```

Expected: `{ "ok": true }`

Verify via tinker:

```php
\App\Models\Core\Site\Enquiry::query()->latest()->first();
// Expected: the submitted enquiry with all fields populated
```

Check that the Horizon queue picked up the notification job and sent the email (in local dev with the log mailer, look at `storage/logs/laravel.log` for the rendered email body).

- [ ] **Step 3: Monitor Nightwatch after deploy**

After merging and letting Laravel Cloud auto-deploy, check Nightwatch for:
- Exceptions in `PublicEnquiryController::submit` route
- Exceptions in `SendEnquiryNotificationJob`
- No regressions in `ProfessionalSectionBlockController` (Nightwatch routes dashboard)

- [ ] **Step 4: Final commit if any cleanup needed**

```bash
git status
# If anything was missed, commit it now.
```

---

## Summary

| # | Task | Files |
|---|---|---|
| 1 | Register contact in config | `config/sidest.php` + config test |
| 2 | Create `site.enquiries` table | Supabase migration |
| 3 | Enquiry model | `app/Models/Core/Site/Enquiry.php` + unit test |
| 4 | Block config validation | `UpsertSectionBlockRequest` + feature test |
| 5 | Publish-gate requirement | `SectionVisibilityService` + feature test |
| 6 | Public submission validation | `PublicEnquiryRequest` |
| 7 | Notification Mailable | `SiteEnquiryNotification` + Blade template |
| 8 | Notification job | `SendEnquiryNotificationJob` |
| 9 | Public submission endpoint | `PublicEnquiryController` + routes + `PublicEnquirySubmissionTest` |
| 10 | Enquiry API Resource | `EnquiryResource` |
| 11 | Professional inbox endpoints | `ProfessionalEnquiryController` + routes + `ProfessionalEnquiryControllerTest` (unit) |
| 12 | Verification | `composer test` + tinker smoke test + Nightwatch check |
