# Professional About Section (Credentials + Experience) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a professional store an "about" section on their profile containing a structured list of credentials (title + issuer + year) and experience entries (role, place, start/end months, description), alongside the existing free-text `bio` intro paragraph.

**Architecture:** Add a single `about jsonb NOT NULL DEFAULT '{}'::jsonb` column to `core.professionals`. Keep the existing `bio text` column unchanged — `bio` remains the intro paragraph, `about` holds two structured arrays: `credentials` and `experience`. Validation of shape lives entirely in Form Requests (the DB only guards that `about` is a JSON object). The column is surfaced on `ProfessionalResource` and on the bespoke `show` payload in `ProfessionalController`, and is writable through both the professional PATCH endpoint and the staff counterpart.

**Tech Stack:** Laravel 12, PostgreSQL (Supabase), Pest 4. No new dependencies.

**JSON shape (the contract):**

```json
{
  "credentials": [
    { "title": "Advanced Colourist", "issuer": "Toni & Guy Academy", "year": 2019 }
  ],
  "experience": [
    { "role": "Senior Stylist", "place": "Rokstar Salon", "start": "2021-03", "end": null, "description": "..." }
  ]
}
```

Caps (to keep payloads sane and prevent accidental abuse):
- `credentials`: max 5 entries; `title` required ≤120; `issuer` optional ≤120; `year` optional integer 1900..current_year+1
- `experience`: max 5 entries; `role` required ≤120; `place` optional ≤120; `start` optional `YYYY-MM`; `end` optional `YYYY-MM`; `end >= start`; `description` optional ≤1000 chars
- `end: null` means "current" (ongoing)
- Both arrays are optional. `about = {}` is the default / empty state.

---

## File Structure

**Create:**
- `supabase/migrations/20260421000000_add_about_to_professionals.sql` — the DB column
- `app/Http/Requests/Concerns/ValidatesProfessionalAbout.php` — shared validation rules/normalization (used by both professional and staff Requests)
- `tests/Feature/Professional/ProfessionalAboutTest.php` — full validation + round-trip feature test
- `tests/Feature/Validation/ProfessionalAboutValidationTest.php` — pure validator unit test for shape edge cases

**Modify:**
- `app/Models/Core/Professional/Professional.php` — add `about` to `$fillable` and `$casts`
- `app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php` — use the trait
- `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php` — use the trait
- `app/Http/Resources/ProfessionalResource.php` — expose `about`
- `app/Http/Controllers/Api/Professional/ProfessionalController.php:62-93` — expose `about` in the bespoke `show` payload
- `docs/api.md` — document the new field on the professional endpoints

---

## Task 1: Supabase migration + test schema parity

**Files:**
- Create: `supabase/migrations/20260421000000_add_about_to_professionals.sql`
- Modify: `tests/Pest.php:92-127` (the `setupProfessionalsTable()` helper — add an `about` column so SQLite tests mirror the new Postgres schema)

- [ ] **Step 1: Write the migration**

Create `supabase/migrations/20260421000000_add_about_to_professionals.sql`:

```sql
-- Add structured "about" payload to core.professionals.
-- Shape (enforced in the application layer, not the DB):
--   { "credentials": [{ title, issuer, year }], "experience": [{ role, place, start, end, description }] }
-- The DB only guarantees that `about` is a JSON object so queries can safely use -> / ->> operators.

ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS about jsonb NOT NULL DEFAULT '{}'::jsonb;

ALTER TABLE core.professionals
    DROP CONSTRAINT IF EXISTS professionals_about_is_object;

ALTER TABLE core.professionals
    ADD CONSTRAINT professionals_about_is_object
    CHECK (jsonb_typeof(about) = 'object');
```

- [ ] **Step 2: Update the test schema helper**

In `tests/Pest.php`, find `setupProfessionalsTable()` (around line 92). Add an `about TEXT NULL` column alongside the others. The `'array'` cast on the model serialises to a JSON string, which SQLite stores fine as `TEXT`:

```php
function setupProfessionalsTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT NULL,
        handle TEXT NULL,
        handle_lc TEXT NULL,
        display_name TEXT NULL,
        first_name TEXT NULL,
        last_name TEXT NULL,
        primary_email TEXT NULL,
        phone TEXT NULL,
        professional_type TEXT NULL,
        status TEXT NULL,
        bio TEXT NULL,
        about TEXT NULL,
        country_code TEXT NULL,
        timezone TEXT NULL,
        onboarding_step INTEGER NULL,
        public_contact_number TEXT NULL,
        public_contact_email TEXT NULL,
        icon_bucket TEXT NULL,
        icon_path TEXT NULL,
        headshot_bucket TEXT NULL,
        headshot_path TEXT NULL,
        location_street_address TEXT NULL,
        location_postcode TEXT NULL,
        location_city TEXT NULL,
        location_state TEXT NULL,
        location_country TEXT NULL,
        qr_slug TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}
```

> **Why update Pest.php**: other Professional tests will also run with the updated model's `$casts = [..., 'about' => 'array']`. If the column doesn't exist in the SQLite shadow table, any test that selects `*` from professionals breaks. The CREATE TABLE is idempotent (`IF NOT EXISTS`), but the column addition isn't — we're modifying the helper so fresh test runs get the new schema. There are ~10 other test files that define their own inline `CREATE TABLE core.professionals` (see `tests/Feature/Subdomain/SubdomainChangeTest.php`, `tests/Feature/Brand/*`, etc.) — **do not** edit those. They each create a minimal table for their own purposes; adding `about` globally there would be scope creep, and those tests never touch the `about` field so they'll keep passing.

- [ ] **Step 3: Apply the migration locally and verify**

Run:

```bash
supabase db reset
```

Expected: migration runs without error. Sanity-check with `psql` or Supabase Studio:

```sql
\d core.professionals
-- confirm `about jsonb NOT NULL DEFAULT '{}'::jsonb`
-- confirm CHECK (jsonb_typeof(about) = 'object')
```

- [ ] **Step 4: Commit**

```bash
git add supabase/migrations/20260421000000_add_about_to_professionals.sql tests/Pest.php
git commit -m "feat(professionals): add about jsonb column for credentials and experience"
```

---

## Task 2: Model — fillable + cast

**Files:**
- Modify: `app/Models/Core/Professional/Professional.php:49-103`

- [ ] **Step 1: Add `about` to `$fillable`**

In `app/Models/Core/Professional/Professional.php`, insert `'about',` immediately after the existing `'bio',` line (currently line 52):

```php
    protected $fillable = [
        'handle',
        'display_name',
        'bio',
        'about',
        'country_code',
        // ... existing entries unchanged
    ];
```

- [ ] **Step 2: Add `about` cast to `array`**

In the same file, extend `$casts`:

```php
    protected $casts = [
        'onboarding_step' => 'integer',
        'stripe_manual_balance_cents' => 'integer',
        'stripe_grace_period_ends_at' => 'datetime',
        'about' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'deletion_requested_at' => 'datetime',
        'deletion_confirmed_at' => 'datetime',
    ];
```

- [ ] **Step 3: Run existing professional tests to ensure nothing regressed**

Run:

```bash
./vendor/bin/pest tests/Feature/Professional
```

Expected: PASS (no changes to behaviour yet — we only added a fillable/cast entry).

- [ ] **Step 4: Commit**

```bash
git add app/Models/Core/Professional/Professional.php
git commit -m "feat(professionals): make about fillable and cast to array"
```

---

## Task 3: Shared `ValidatesProfessionalAbout` trait (TDD — validator unit test first)

**Files:**
- Create: `tests/Feature/Validation/ProfessionalAboutValidationTest.php`
- Create: `app/Http/Requests/Concerns/ValidatesProfessionalAbout.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Validation/ProfessionalAboutValidationTest.php`:

```php
<?php

use App\Http\Requests\Api\Professional\UpdateProfessionalRequest;
use Illuminate\Http\Request;

function validateAboutPayload(array $about): \Illuminate\Contracts\Validation\Validator
{
    $request = Request::create('/api/test', 'PATCH', ['about' => $about]);
    $formRequest = UpdateProfessionalRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();
    } catch (\Illuminate\Validation\ValidationException $e) {
        return $e->validator;
    }

    return validator($formRequest->validated(), []);
}

it('accepts an empty about object', function () {
    $v = validateAboutPayload([]);
    expect($v->fails())->toBeFalse();
});

it('accepts a full valid about payload', function () {
    $v = validateAboutPayload([
        'credentials' => [
            ['title' => 'Advanced Colourist', 'issuer' => 'Toni & Guy', 'year' => 2019],
        ],
        'experience' => [
            ['role' => 'Senior Stylist', 'place' => 'Rokstar', 'start' => '2021-03', 'end' => null, 'description' => 'Led colour team.'],
        ],
    ]);
    expect($v->fails())->toBeFalse();
});

it('rejects credentials with missing title', function () {
    $v = validateAboutPayload(['credentials' => [['issuer' => 'X', 'year' => 2020]]]);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('about.credentials.0.title'))->toBeTrue();
});

it('rejects credentials year out of range', function () {
    $v = validateAboutPayload(['credentials' => [['title' => 'X', 'year' => 1800]]]);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('about.credentials.0.year'))->toBeTrue();
});

it('rejects experience with bad start format', function () {
    $v = validateAboutPayload(['experience' => [['role' => 'X', 'start' => '2021/03']]]);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('about.experience.0.start'))->toBeTrue();
});

it('rejects experience where end is before start', function () {
    $v = validateAboutPayload(['experience' => [[
        'role' => 'X', 'start' => '2022-06', 'end' => '2021-01',
    ]]]);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('about.experience.0.end'))->toBeTrue();
});

it('accepts experience with null end (ongoing role)', function () {
    $v = validateAboutPayload(['experience' => [[
        'role' => 'X', 'start' => '2022-06', 'end' => null,
    ]]]);
    expect($v->fails())->toBeFalse();
});

it('rejects more than 5 credentials', function () {
    $credentials = array_fill(0, 6, ['title' => 'X']);
    $v = validateAboutPayload(['credentials' => $credentials]);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('about.credentials'))->toBeTrue();
});

it('rejects more than 5 experience entries', function () {
    $experience = array_fill(0, 6, ['role' => 'X']);
    $v = validateAboutPayload(['experience' => $experience]);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('about.experience'))->toBeTrue();
});

it('rejects description over 1000 chars', function () {
    $v = validateAboutPayload(['experience' => [[
        'role' => 'X', 'description' => str_repeat('a', 1001),
    ]]]);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('about.experience.0.description'))->toBeTrue();
});

it('strips HTML tags from description', function () {
    $request = Request::create('/api/test', 'PATCH', ['about' => [
        'experience' => [['role' => 'X', 'description' => '<script>bad</script>clean text']],
    ]]);
    $formRequest = UpdateProfessionalRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));
    $formRequest->validateResolved();

    $validated = $formRequest->validated();
    expect($validated['about']['experience'][0]['description'])->toBe('clean text');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
./vendor/bin/pest tests/Feature/Validation/ProfessionalAboutValidationTest.php
```

Expected: FAIL — "empty about" probably passes, but every structured-shape assertion fails because the rules don't exist yet.

- [ ] **Step 3: Create the `ValidatesProfessionalAbout` trait**

Create `app/Http/Requests/Concerns/ValidatesProfessionalAbout.php`:

```php
<?php

namespace App\Http\Requests\Concerns;

// V2: Shared validation + normalization for the professional "about" payload
// (credentials + experience). Used by UpdateProfessionalRequest (self-serve)
// and StaffUpdateProfessionalRequest so both endpoints enforce identical shape.
trait ValidatesProfessionalAbout
{
    /**
     * Merge these into the rules() array of the consuming Form Request.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function aboutRules(): array
    {
        $currentYear = (int) date('Y');

        return [
            'about' => ['sometimes', 'array'],
            'about.credentials' => ['sometimes', 'array', 'max:5'],
            'about.credentials.*' => ['array:title,issuer,year'],
            'about.credentials.*.title' => ['required', 'string', 'max:120'],
            'about.credentials.*.issuer' => ['sometimes', 'nullable', 'string', 'max:120'],
            'about.credentials.*.year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:' . ($currentYear + 1)],

            'about.experience' => ['sometimes', 'array', 'max:5'],
            'about.experience.*' => ['array:role,place,start,end,description'],
            'about.experience.*.role' => ['required', 'string', 'max:120'],
            'about.experience.*.place' => ['sometimes', 'nullable', 'string', 'max:120'],
            'about.experience.*.start' => ['sometimes', 'nullable', 'string', 'date_format:Y-m'],
            'about.experience.*.end' => ['sometimes', 'nullable', 'string', 'date_format:Y-m'],
            'about.experience.*.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Call from the Form Request's prepareForValidation() before any other merges.
     * Strips HTML from free-text fields and coerces empty strings to null so the
     * JSONB payload stays clean.
     */
    protected function normalizeAboutPayload(): void
    {
        $about = $this->input('about');
        if (! is_array($about)) {
            return;
        }

        if (isset($about['credentials']) && is_array($about['credentials'])) {
            foreach ($about['credentials'] as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $about['credentials'][$i]['title'] = $this->cleanStringOrNull($row['title'] ?? null);
                $about['credentials'][$i]['issuer'] = $this->cleanStringOrNull($row['issuer'] ?? null);
                // year is left as-is; validator coerces / rejects
            }
        }

        if (isset($about['experience']) && is_array($about['experience'])) {
            foreach ($about['experience'] as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $about['experience'][$i]['role'] = $this->cleanStringOrNull($row['role'] ?? null);
                $about['experience'][$i]['place'] = $this->cleanStringOrNull($row['place'] ?? null);
                $about['experience'][$i]['description'] = $this->cleanStringOrNull($row['description'] ?? null);
                // start / end are kept as supplied; validator enforces Y-m format
                if (($about['experience'][$i]['start'] ?? null) === '') {
                    $about['experience'][$i]['start'] = null;
                }
                if (($about['experience'][$i]['end'] ?? null) === '') {
                    $about['experience'][$i]['end'] = null;
                }
            }
        }

        $this->merge(['about' => $about]);
    }

    /**
     * Cross-field rule: each experience entry's `end` must be >= `start` when both set.
     * Call from withValidator() in the Form Request.
     */
    protected function validateExperienceDateOrder(\Illuminate\Validation\Validator $validator): void
    {
        $experience = (array) data_get($this->validated() ?: $this->all(), 'about.experience', []);
        foreach ($experience as $i => $row) {
            $start = $row['start'] ?? null;
            $end = $row['end'] ?? null;
            if (is_string($start) && is_string($end) && $end < $start) {
                $validator->errors()->add(
                    "about.experience.$i.end",
                    'The end month must be on or after the start month.'
                );
            }
        }
    }

    private function cleanStringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(strip_tags($value));

        return $value === '' ? null : $value;
    }
}
```

- [ ] **Step 4: Wire the trait into `UpdateProfessionalRequest`**

Modify `app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php`:

1. Add the trait import and `use` statement:

```php
use App\Http\Requests\Concerns\NormalizesProfessionalType;
use App\Http\Requests\Concerns\ValidatesProfessionalAbout;
// ...

class UpdateProfessionalRequest extends BaseFormRequest
{
    use NormalizesProfessionalType;
    use ValidatesProfessionalAbout;
```

2. Merge `aboutRules()` into `rules()`:

```php
    public function rules(): array
    {
        return array_merge([
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // ... all existing rules unchanged
            'location_country' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], $this->aboutRules());
    }
```

3. Call `normalizeAboutPayload()` at the **top** of `prepareForValidation()` (before any `$merge` work), and add `withValidator()`:

```php
    protected function prepareForValidation(): void
    {
        $this->normalizeAboutPayload();

        $phone = $this->input('phone');
        // ... existing body unchanged
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $this->validateExperienceDateOrder($v);
        });
    }
```

- [ ] **Step 5: Run the validator test again — should now pass**

Run:

```bash
./vendor/bin/pest tests/Feature/Validation/ProfessionalAboutValidationTest.php
```

Expected: PASS (all 11 cases).

- [ ] **Step 6: Run the full validation suite to make sure nothing else broke**

Run:

```bash
./vendor/bin/pest tests/Feature/Validation
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Concerns/ValidatesProfessionalAbout.php \
        app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php \
        tests/Feature/Validation/ProfessionalAboutValidationTest.php
git commit -m "feat(professionals): validate about credentials and experience payload"
```

---

## Task 4: Staff update Request parity

**Files:**
- Modify: `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php`

- [ ] **Step 1: Wire the trait in**

In `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php`:

1. Add the import + `use`:

```php
use App\Http\Requests\Concerns\NormalizesProfessionalType;
use App\Http\Requests\Concerns\ValidatesProfessionalAbout;
// ...

class StaffUpdateProfessionalRequest extends BaseFormRequest
{
    use NormalizesProfessionalType;
    use ValidatesProfessionalAbout;
```

2. Merge `aboutRules()` into `rules()`:

```php
    public function rules(): array
    {
        return array_merge([
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            // ... all existing rules unchanged
            'location_country' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], $this->aboutRules());
    }
```

3. Call `normalizeAboutPayload()` at the top of `prepareForValidation()`, and add `withValidator()`:

```php
    protected function prepareForValidation(): void
    {
        $this->normalizeAboutPayload();

        $phone = $this->input('phone');
        // ... existing body unchanged
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $this->validateExperienceDateOrder($v);
        });
    }
```

- [ ] **Step 2: Run staff tests**

Run:

```bash
./vendor/bin/pest tests/Feature/Staff
```

Expected: PASS (no existing tests exercise `about`, so behaviour for existing fields is unchanged).

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php
git commit -m "feat(staff): accept about payload on professional update"
```

---

## Task 5: Expose `about` on read paths

**Files:**
- Modify: `app/Http/Resources/ProfessionalResource.php:13-40`
- Modify: `app/Http/Controllers/Api/Professional/ProfessionalController.php:62-93`

- [ ] **Step 1: Add `about` to `ProfessionalResource`**

Insert the new line after the `'bio'` line (line 20) in `app/Http/Resources/ProfessionalResource.php`:

```php
            'bio' => $this->bio,
            'about' => (object) ($this->about ?? []),
            'phone' => $this->phone,
```

> **Why `(object) (... ?? [])`**: Laravel's `'array'` cast returns `[]` for an empty `'{}'::jsonb` column (not `null`), so a plain `?? (object) []` fallback wouldn't fire. Casting to `(object)` unconditionally means an empty about JSON-encodes as `{}` and a populated one encodes as `{"credentials":[...], "experience":[...]}`. The inner `credentials` / `experience` arrays stay as plain arrays because only the outer value is coerced.

- [ ] **Step 2: Add `about` to the bespoke `show` payload**

In `app/Http/Controllers/Api/Professional/ProfessionalController.php`, insert the new line after the `'bio' => $pro->bio,` line (line 73):

```php
                'bio' => $pro->bio,
                'about' => (object) ($pro->about ?? []),
                'country_code' => $pro->country_code,
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Resources/ProfessionalResource.php \
        app/Http/Controllers/Api/Professional/ProfessionalController.php
git commit -m "feat(professionals): expose about on read endpoints"
```

---

## Task 6: Persistence + resource round-trip test

**Files:**
- Create: `tests/Feature/Professional/ProfessionalAboutTest.php`

**Why not `patchJson` through the HTTP kernel:** The `/api/professional` PATCH route sits behind Supabase JWT auth middleware that resolves the current professional via `$request->attributes->get('professional')`. Tests in this codebase don't mock the JWT flow — they either hit middleware directly (like `ReadOnlyEnforcementTest.php`) or assert behaviour at the Model/Request/Resource layer. This test does the latter: the validator layer is fully covered by Task 3, so here we cover **persistence** (cast round-trip through the DB) and **serialisation** (Resource exposes the value correctly).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Professional/ProfessionalAboutTest.php`:

```php
<?php

use App\Http\Resources\ProfessionalResource;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
});

function makeAboutProfessional(array $attrs = []): Professional
{
    $id = (string) Str::uuid();
    $handle = 'about-' . substr($id, 0, 8);

    return Professional::create(array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'About Pro',
        'first_name' => 'About',
        'phone' => '+61400000000',
        'primary_email' => $handle . '@example.com',
        'qr_slug' => 'q-' . Str::random(8),
        'professional_type' => 'professional',
        'status' => 'active',
    ], $attrs));
}

it('persists a full about payload and reads it back as an array', function () {
    $pro = makeAboutProfessional();

    $pro->about = [
        'credentials' => [
            ['title' => 'Advanced Colourist', 'issuer' => 'Toni & Guy', 'year' => 2019],
        ],
        'experience' => [
            ['role' => 'Senior Stylist', 'place' => 'Rokstar', 'start' => '2021-03', 'end' => null, 'description' => 'Led colour team.'],
        ],
    ];
    $pro->save();

    $fresh = Professional::query()->where('id', $pro->id)->first();

    expect($fresh->about)->toBeArray();
    expect($fresh->about['credentials'][0]['title'])->toBe('Advanced Colourist');
    expect($fresh->about['credentials'][0]['year'])->toBe(2019);
    expect($fresh->about['experience'][0]['start'])->toBe('2021-03');
    expect($fresh->about['experience'][0]['end'])->toBeNull();
});

it('exposes about through ProfessionalResource', function () {
    $pro = makeAboutProfessional([
        'about' => [
            'credentials' => [['title' => 'Cert', 'issuer' => 'Academy', 'year' => 2020]],
            'experience' => [],
        ],
    ]);

    $array = (new ProfessionalResource($pro->fresh()))->toArray(request());

    expect($array)->toHaveKey('about');
    expect($array['about']['credentials'][0]['title'])->toBe('Cert');
    expect($array['about']['experience'])->toBe([]);
});

it('returns an object that JSON-encodes as {} when about has never been set', function () {
    $pro = makeAboutProfessional();

    $array = (new ProfessionalResource($pro->fresh()))->toArray(request());

    // The resource casts $this->about to (object), so json_encode renders
    // an empty about as '{}' (not '[]').
    expect(json_encode($array['about']))->toBe('{}');
});

it('fill() accepts about from validated Request payload', function () {
    // Simulates what the controller does: $professional->fill($request->validated())
    $pro = makeAboutProfessional();

    $pro->fill([
        'display_name' => 'Renamed',
        'about' => [
            'credentials' => [['title' => 'New Cert']],
        ],
    ])->save();

    $fresh = $pro->fresh();
    expect($fresh->display_name)->toBe('Renamed');
    expect($fresh->about['credentials'][0]['title'])->toBe('New Cert');
});
```

- [ ] **Step 2: Run the test**

Run:

```bash
./vendor/bin/pest tests/Feature/Professional/ProfessionalAboutTest.php
```

Expected: PASS on all four cases. If the third case fails, inspect the resource output — the acceptable shapes are `(object) []` (from our resource change) or `[]` (if the DB round-trip gave us an empty array). Both JSON-encode as `{}` or `[]` respectively; the important thing is callers don't get `null`.

- [ ] **Step 3: Run the full Professional test group as a regression check**

Run:

```bash
./vendor/bin/pest tests/Feature/Professional
```

Expected: PASS. If unrelated tests break, the most likely cause is the `'about' => 'array'` cast changing the shape of `$pro->toArray()` output for a test that inspected all attributes — fix those assertions to ignore `about` or update them to expect the new key.

- [ ] **Step 4: Run the full suite**

Run:

```bash
composer test
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Professional/ProfessionalAboutTest.php
git commit -m "test(professionals): persistence and resource round-trip for about"
```

---

## Task 7: Document the endpoint

**Files:**
- Modify: `docs/api.md` — find the professional PATCH and `show` sections

- [ ] **Step 1: Update `docs/api.md`**

Search `docs/api.md` for the professional update endpoint (`PATCH /api/professional` or similar) and the show endpoint. Under each, add `about` to the response/request field list with this description:

```markdown
- `about` (object, optional): Structured about-me content.
  - `credentials`: array of up to 5 entries, each `{ title (required, ≤120), issuer (≤120), year (1900..current+1) }`.
  - `experience`: array of up to 5 entries, each `{ role (required, ≤120), place (≤120), start (YYYY-MM), end (YYYY-MM or null for ongoing), description (≤1000) }`.
  - `end` must be on or after `start` when both are set.
  - Omit the field on PATCH to leave existing data untouched. Send `{}` to clear.
```

- [ ] **Step 2: Commit**

```bash
git add docs/api.md
git commit -m "docs(api): document professional about field"
```

---

## Task 8: Final verification

- [ ] **Step 1: Full test suite + linter**

Run:

```bash
composer test
php artisan pint --test
```

Expected: both PASS. If Pint wants to reformat anything, run `php artisan pint` and commit the style fixes in a separate `style:` commit.

- [ ] **Step 2: Manual smoke check via tinker**

Run:

```bash
php artisan tinker
```

Then:

```php
$pro = \App\Models\Core\Professional\Professional::first();
$pro->about = ['credentials' => [['title' => 'Smoke Test', 'year' => 2024]]];
$pro->save();
$pro->fresh()->about;
```

Expected: array with the credential you just set. Confirms the cast + column work end-to-end against Postgres (not just SQLite).

- [ ] **Step 3: Confirm no Nightwatch regressions**

After deploying to a branch environment (or the next merge), spot-check Nightwatch for new exceptions on the professional update route. None expected, but the `about` cast on every load is a minor touch-point worth verifying.

---

## Out of scope (tracked for follow-up, not this plan)

- **Verified credentials** (brand-issued badges, FK to `brand_professional_id`): would justify extracting `credentials` into a child table. Revisit if/when product requirement lands.
- **Searchable experience** ("find all stylists with salon X in their history"): same — JSONB `GIN` index is possible but extraction is cleaner when needed.
- **Public-site rendering**: this plan only covers backend storage + API. The dashboard form and the public-site about block are separate frontend work.
- **Bootstrap endpoint**: `app/Http/Controllers/Api/PublicSite/BootstrapController.php` initialises new professionals with `'bio' => null`. No change needed — the DB default of `'{}'::jsonb` handles `about` automatically, and new signups start with an empty about. Leave the Bootstrap flow untouched.
