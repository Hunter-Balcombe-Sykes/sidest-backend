# BaseFormRequest Helpers (PR 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lift four duplicated input-sanitization patterns out of individual Form Requests and into `BaseFormRequest` plus one new trait, eliminating ~60 lines of repeated logic across 12 call sites and removing the silent-drift risk between the self-serve and staff variants of the professional/customer endpoints.

**Architecture:** Three key-array helpers added to `BaseFormRequest` (`normalizePhones`, `cleanText`, `lowercaseEmails`) follow the existing `trimStrings` / `lowercaseStrings` shape — each takes an array of input keys and merges normalized values. A fourth value-level helper (`cleanString`) is lifted out of `cleanText` so that the trait `ValidatesProfessionalAbout` and the nested `settings.*` loops in `UpdateSiteRequest` / `UpsertSectionBlockRequest` can share the exact same transform without re-implementing it — collapsing the four pre-existing copies of the strip-tags + control-chars + null-coerce logic onto one source of truth. One narrow trait (`Concerns\ResolvesPublicSiteSubdomain`) covers the route-or-header subdomain pattern shared by the public-site analytics endpoints. Each helper is unit-tested in isolation; existing feature tests provide regression coverage for the migrated call sites.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4. No new dependencies.

**PR ordering with PR1:** Both PR1 (`final authorize()`) and PR2 modify `BaseFormRequest.php`, but on disjoint regions — PR1 only touches the `authorize()` method (lines 11–20) while PR2 only appends new methods after `sanitizeEmails`. They are merge-order safe in either direction; this plan does not require PR1 to be merged first.

---

## File Structure

**Modify (1 — base class):**
- `app/Http/Requests/BaseFormRequest.php` — add `normalizePhones`, `cleanString`, `cleanText`, `lowercaseEmails`.

**Create (1 — trait):**
- `app/Http/Requests/Concerns/ResolvesPublicSiteSubdomain.php` — `mergeSubdomainFromRoute(?string $headerName = null)`.

**Modify (1 — existing trait):**
- `app/Http/Requests/Concerns/ValidatesProfessionalAbout.php` — make `cleanStringOrNull` delegate to `BaseFormRequest::cleanString` so the trait stops being a second source of truth.

**Modify (12 — call sites):**

*Phone normalization (5 files, 7 call sites):*
- `app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php`
- `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php`
- `app/Http/Requests/Api/Professional/Customer/StoreCustomerRequest.php`
- `app/Http/Requests/Api/Professional/Customer/UpdateCustomerRequest.php`
- `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateCustomerRequest.php`

*HTML strip + null-on-empty (5 files):*
- `app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php`
- `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php`
- `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php`
- `app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php` *(bio field — same file as above)*
- `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php` *(bio field — adds parity with the self-serve variant; pre-existing drift, fixed here while we're already in the file)*

*Lowercase-or-null email (2 files):*
- `app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php`
- `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php`

*Subdomain trait (3 files):*
- `app/Http/Requests/Api/PublicSite/Analytics/PageviewRequest.php`
- `app/Http/Requests/Api/PublicSite/Analytics/ClickRequest.php`
- `app/Http/Requests/Api/PublicSite/PublicSiteShowRequest.php`

**Create (4 — unit tests):**
- `tests/Unit/Http/Requests/BaseFormRequestNormalizePhonesTest.php`
- `tests/Unit/Http/Requests/BaseFormRequestCleanTextTest.php`
- `tests/Unit/Http/Requests/BaseFormRequestLowercaseEmailsTest.php`
- `tests/Unit/Http/Requests/Concerns/ResolvesPublicSiteSubdomainTest.php`

---

## Behaviour Notes (Read Before Starting)

Four intentional, narrow behaviour shifts come with this refactor. All are improvements; all are exercised by the targeted feature tests in Tasks 6 and 8 (the `Professional` and `Site|Block|Section|Professional` filters).

1. **`cleanText` returns `null` on empty.** `StoreLinkBlockRequest::title` and `UpdateSiteRequest::settings.*` previously left empty strings as empty strings after `strip_tags(trim(...))`. They will now become `null`. The fields are `nullable` in their respective rule sets, so DB writes accept either; coercing empty to null is consistent with `UpdateProfessionalRequest::bio`, the customer requests, and `ValidatesProfessionalAbout::cleanStringOrNull` — i.e., it brings the outliers into line with the majority.
2. **Control-char strip (`\x00-\x1F\x7F`) is now applied everywhere the new helpers are used.** Currently only `StoreLinkBlockRequest::title` strips control chars; `UpdateSiteRequest::settings.*`, `UpsertSectionBlockRequest::settings.*`, the countdown sanitizer's `$clean` closure, and `ValidatesProfessionalAbout::cleanStringOrNull` all do not. After this PR, every site that delegates to `cleanString` (directly or via `cleanText`) gets the same defense-in-depth strip. No call site loses a behaviour it relied on.
3. **`StaffUpdateProfessionalRequest::bio` now gets the same HTML-strip + null-coerce treatment as `UpdateProfessionalRequest::bio`.** The self-serve variant has been stripping bio HTML for months; the staff variant silently did not. Fixed here (Task 8) since we are already editing both files and the divergence is exactly the staff/self-serve drift this refactor is meant to eliminate.
4. **`<script>` and `<style>` block *contents* are now removed, not just the wrappers.** PHP's `strip_tags` removes only the tag delimiters, leaving body text intact (`<script>alert(1)</script>Hello` → `alert(1)Hello`). `cleanString` runs a regex pre-pass (`/<(script|style)\b[^>]*>.*?<\/\1>/is`) that nukes the entire block first, so injected JS/CSS payloads cannot survive even as plain text. Two existing feature tests assert the new shape: `tests/Feature/Site/LinkBlockSocialValidationTest.php` (title `<script>alert(1)</script>Book` → `Book`) and `tests/Feature/Countdown/CountdownSectionValidationTest.php` (headline `<script>alert(1)</script>Live now` → `Live now`). Storage shape narrows for any field touched by `cleanString`; check Hydrogen renderers and frontend Resource transformers if anything depends on the old "tag wrapper gone, content survives" semantics.

**Out of scope for this PR:**
- `PublicEnquiryRequest` and `PublicCustomerLeadRequest` — they sanitize phones via `trim(strip_tags(...))` instead of the digits-and-plus regex. Unifying them is a separate decision (changes user-visible storage shape).
- `PublicWaitlistSignupRequest` — its phone normalizer has extra `+`-rewriting logic; leave it alone.
- `UpdateSiteRequest`'s subdomain — uses `route('subdomain')`-only pattern AND has a route-data fallback chain different enough that lifting it out has no payoff. Leave as-is.

---

## Task 1: Capture green baseline

**Files:**
- None — read-only verification.

- [ ] **Step 1: Run the full suite to confirm green starting state**

Run: `composer test`
Expected: PASS. If anything fails, stop and investigate — those failures are not caused by this plan.

---

## Task 2: Add `normalizePhones` helper (TDD)

**Files:**
- Test: `tests/Unit/Http/Requests/BaseFormRequestNormalizePhonesTest.php`
- Modify: `app/Http/Requests/BaseFormRequest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Http/Requests/BaseFormRequestNormalizePhonesTest.php`:

```php
<?php

use App\Http\Requests\BaseFormRequest;

function makePhoneRequest(array $data): BaseFormRequest
{
    $request = new class extends BaseFormRequest {
        public function rules(): array { return []; }
        public function exposeNormalizePhones(array $keys): void { $this->normalizePhones($keys); }
    };
    $request->merge($data);
    return $request;
}

it('strips non-digit non-plus characters and leaves digits intact', function () {
    $r = makePhoneRequest(['phone' => '+1 (555) 123-4567']);
    $r->exposeNormalizePhones(['phone']);
    expect($r->input('phone'))->toBe('+15551234567');
});

it('coerces empty strings to null', function () {
    $r = makePhoneRequest(['phone' => '   ']);
    $r->exposeNormalizePhones(['phone']);
    expect($r->input('phone'))->toBeNull();
});

it('leaves non-string values unchanged', function () {
    $r = makePhoneRequest(['phone' => 12345, 'other' => null]);
    $r->exposeNormalizePhones(['phone', 'other']);
    expect($r->input('phone'))->toBe(12345);
    expect($r->input('other'))->toBeNull();
});

it('skips keys that are not present', function () {
    $r = makePhoneRequest([]);
    $r->exposeNormalizePhones(['phone']);
    expect($r->has('phone'))->toBeFalse();
});

it('handles multiple keys in one call', function () {
    $r = makePhoneRequest(['phone' => '555-1212', 'public_contact_number' => '+44 20 7946 0958']);
    $r->exposeNormalizePhones(['phone', 'public_contact_number']);
    expect($r->input('phone'))->toBe('5551212');
    expect($r->input('public_contact_number'))->toBe('+442079460958');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Http/Requests/BaseFormRequestNormalizePhonesTest.php`
Expected: FAIL — `Method App\Http\Requests\BaseFormRequest::normalizePhones does not exist`.

- [ ] **Step 3: Implement the helper**

Edit `app/Http/Requests/BaseFormRequest.php`. Add this method below `sanitizeEmails`:

```php
    /**
     * Normalize phone-like inputs to digits and a leading `+` only.
     * Strips whitespace, parens, dashes, and any other punctuation. Empty
     * results coerce to null so downstream code never has to distinguish
     * '' from null. Skips keys that are absent or not strings.
     */
    protected function normalizePhones(array $keys): void
    {
        $data = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $value = $this->input($key);
            if (! is_string($value)) {
                continue;
            }
            $normalized = preg_replace('/[^\d+]/', '', trim($value));
            $data[$key] = $normalized === '' ? null : $normalized;
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Http/Requests/BaseFormRequestNormalizePhonesTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/BaseFormRequest.php tests/Unit/Http/Requests/BaseFormRequestNormalizePhonesTest.php
git commit -m "feat(requests): add BaseFormRequest::normalizePhones helper"
```

---

## Task 3: Add `cleanText` helper (TDD)

**Files:**
- Test: `tests/Unit/Http/Requests/BaseFormRequestCleanTextTest.php`
- Modify: `app/Http/Requests/BaseFormRequest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Http/Requests/BaseFormRequestCleanTextTest.php`:

```php
<?php

use App\Http\Requests\BaseFormRequest;

function makeCleanTextRequest(array $data): BaseFormRequest
{
    $request = new class extends BaseFormRequest {
        public function rules(): array { return []; }
        public function exposeCleanText(array $keys): void { $this->cleanText($keys); }
    };
    $request->merge($data);
    return $request;
}

it('strips HTML tags and trims whitespace', function () {
    $r = makeCleanTextRequest(['title' => '  <script>alert(1)</script>Hello  ']);
    $r->exposeCleanText(['title']);
    expect($r->input('title'))->toBe('Hello');
});

it('coerces empty results to null', function () {
    $r = makeCleanTextRequest(['title' => '<b></b>   ']);
    $r->exposeCleanText(['title']);
    expect($r->input('title'))->toBeNull();
});

it('strips ASCII control characters', function () {
    $r = makeCleanTextRequest(['title' => "Hello\x00\x07World\x7F"]);
    $r->exposeCleanText(['title']);
    expect($r->input('title'))->toBe('HelloWorld');
});

it('leaves non-string values unchanged', function () {
    $r = makeCleanTextRequest(['title' => 42, 'other' => null]);
    $r->exposeCleanText(['title', 'other']);
    expect($r->input('title'))->toBe(42);
    expect($r->input('other'))->toBeNull();
});

it('skips keys that are not present', function () {
    $r = makeCleanTextRequest([]);
    $r->exposeCleanText(['title']);
    expect($r->has('title'))->toBeFalse();
});

it('handles multiple keys in one call', function () {
    $r = makeCleanTextRequest(['title' => ' a ', 'bio' => '<p>b</p>']);
    $r->exposeCleanText(['title', 'bio']);
    expect($r->input('title'))->toBe('a');
    expect($r->input('bio'))->toBe('b');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Http/Requests/BaseFormRequestCleanTextTest.php`
Expected: FAIL — `Method App\Http\Requests\BaseFormRequest::cleanText does not exist`.

- [ ] **Step 3: Implement the helpers**

Edit `app/Http/Requests/BaseFormRequest.php`. Add **two** methods below `normalizePhones` — a value-level `cleanString` and the key-array `cleanText` that delegates to it. The split exists so the trait `ValidatesProfessionalAbout` (Task 9) and the nested `settings.*` loops (Task 8 steps 4 and 5) can share the same transform without duplication.

```php
    /**
     * Value-level twin of `cleanText`. Strips HTML tags, ASCII control chars,
     * and surrounding whitespace from a single string; returns null for empty
     * results AND for non-string input. Lifted out of cleanText so traits
     * (ValidatesProfessionalAbout) and nested-array loops (settings.*) can
     * share the exact same transform without re-implementing it.
     */
    protected static function cleanString(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $stripped = preg_replace('/[\x00-\x1F\x7F]/', '', strip_tags($value));
        $cleaned = trim((string) $stripped);

        return $cleaned === '' ? null : $cleaned;
    }

    /**
     * Clean user-authored text inputs: strip HTML tags, ASCII control chars,
     * and surrounding whitespace; coerce empty results to null. Defense-in-depth
     * against stored XSS for fields that are echoed by the frontend. Skips keys
     * that are absent or not strings.
     */
    protected function cleanText(array $keys): void
    {
        $data = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $value = $this->input($key);
            if (! is_string($value)) {
                continue;
            }
            $data[$key] = static::cleanString($value);
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Http/Requests/BaseFormRequestCleanTextTest.php`
Expected: PASS (6 tests). The existing test suite covers `cleanString` indirectly through every `cleanText` case — no separate test file for `cleanString` because it has no behaviour the public `cleanText` doesn't already exercise (the only branch unique to `cleanString` — non-string input → null — is unreachable through `cleanText`'s `is_string` guard, but is exercised by Tasks 8 and 9 callers and verified by their feature tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/BaseFormRequest.php tests/Unit/Http/Requests/BaseFormRequestCleanTextTest.php
git commit -m "feat(requests): add BaseFormRequest::cleanString and cleanText helpers"
```

---

## Task 4: Add `lowercaseEmails` helper (TDD)

**Files:**
- Test: `tests/Unit/Http/Requests/BaseFormRequestLowercaseEmailsTest.php`
- Modify: `app/Http/Requests/BaseFormRequest.php`

**Why a new helper rather than reusing `sanitizeEmails`:** the existing `sanitizeEmails` (alias for `lowercaseStrings`) leaves an empty string as `''`. The two duplicated `lowerOrNull` private methods convert empty to `null` to avoid the unique-index trap on `professionals.primary_email`. The new helper preserves the empty-to-null behaviour those call sites depend on, keeping `sanitizeEmails` available unchanged for any caller that wants the looser semantics.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Http/Requests/BaseFormRequestLowercaseEmailsTest.php`:

```php
<?php

use App\Http\Requests\BaseFormRequest;

function makeEmailRequest(array $data): BaseFormRequest
{
    $request = new class extends BaseFormRequest {
        public function rules(): array { return []; }
        public function exposeLowercaseEmails(array $keys): void { $this->lowercaseEmails($keys); }
    };
    $request->merge($data);
    return $request;
}

it('lowercases and trims', function () {
    $r = makeEmailRequest(['email' => '  Foo@Bar.COM  ']);
    $r->exposeLowercaseEmails(['email']);
    expect($r->input('email'))->toBe('foo@bar.com');
});

it('coerces empty strings to null', function () {
    $r = makeEmailRequest(['email' => '   ']);
    $r->exposeLowercaseEmails(['email']);
    expect($r->input('email'))->toBeNull();
});

it('leaves non-string values unchanged', function () {
    $r = makeEmailRequest(['email' => 0, 'other' => false]);
    $r->exposeLowercaseEmails(['email', 'other']);
    expect($r->input('email'))->toBe(0);
    expect($r->input('other'))->toBeFalse();
});

it('skips keys that are not present', function () {
    $r = makeEmailRequest([]);
    $r->exposeLowercaseEmails(['email']);
    expect($r->has('email'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Http/Requests/BaseFormRequestLowercaseEmailsTest.php`
Expected: FAIL — `Method App\Http\Requests\BaseFormRequest::lowercaseEmails does not exist`.

- [ ] **Step 3: Implement the helper**

Edit `app/Http/Requests/BaseFormRequest.php`. Add below `cleanText`:

```php
    /**
     * Lowercase email-like inputs and coerce empty strings to null. Use this
     * for fields backed by a unique index where the difference between '' and
     * null matters (e.g. professionals.primary_email). For looser semantics
     * use `sanitizeEmails`.
     */
    protected function lowercaseEmails(array $keys): void
    {
        $data = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $value = $this->input($key);
            if (! is_string($value)) {
                continue;
            }
            $normalized = mb_strtolower(trim($value));
            $data[$key] = ($normalized === '') ? null : $normalized;
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Http/Requests/BaseFormRequestLowercaseEmailsTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/BaseFormRequest.php tests/Unit/Http/Requests/BaseFormRequestLowercaseEmailsTest.php
git commit -m "feat(requests): add BaseFormRequest::lowercaseEmails helper"
```

---

## Task 5: Add `ResolvesPublicSiteSubdomain` trait (TDD)

**Files:**
- Test: `tests/Unit/Http/Requests/Concerns/ResolvesPublicSiteSubdomainTest.php`
- Create: `app/Http/Requests/Concerns/ResolvesPublicSiteSubdomain.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Http/Requests/Concerns/ResolvesPublicSiteSubdomainTest.php`:

```php
<?php

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ResolvesPublicSiteSubdomain;
use Illuminate\Routing\Route;

function makeSubdomainRequest(?string $routeValue, ?string $headerValue, ?string $bodyValue = null): BaseFormRequest
{
    $request = new class extends BaseFormRequest {
        use ResolvesPublicSiteSubdomain;
        public function rules(): array { return []; }
        public function exposeMerge(?string $headerName = null): void { $this->mergeSubdomainFromRoute($headerName); }
    };

    if ($bodyValue !== null) {
        $request->merge(['subdomain' => $bodyValue]);
    }
    if ($headerValue !== null) {
        $request->headers->set('X-Site-Subdomain', $headerValue);
    }
    if ($routeValue !== null) {
        $route = new Route(['GET'], '/x/{subdomain}', []);
        $route->bind($request);
        $route->setParameter('subdomain', $routeValue);
        $request->setRouteResolver(fn () => $route);
    }
    return $request;
}

it('lowercases the route subdomain when present', function () {
    $r = makeSubdomainRequest('FooBar', null);
    $r->exposeMerge();
    expect($r->input('subdomain'))->toBe('foobar');
});

it('falls back to the configured header when route is empty', function () {
    $r = makeSubdomainRequest(null, '  HeaderName  ');
    $r->exposeMerge('X-Site-Subdomain');
    expect($r->input('subdomain'))->toBe('headername');
});

it('does not fall back to the header when no header name is supplied', function () {
    $r = makeSubdomainRequest(null, 'HeaderName');
    $r->exposeMerge();
    expect($r->has('subdomain'))->toBeFalse();
});

it('prefers the route value over the header value', function () {
    $r = makeSubdomainRequest('FromRoute', 'FromHeader');
    $r->exposeMerge('X-Site-Subdomain');
    expect($r->input('subdomain'))->toBe('fromroute');
});

it('leaves request untouched when neither source is present', function () {
    $r = makeSubdomainRequest(null, null);
    $r->exposeMerge('X-Site-Subdomain');
    expect($r->has('subdomain'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Http/Requests/Concerns/ResolvesPublicSiteSubdomainTest.php`
Expected: FAIL — trait does not exist.

- [ ] **Step 3: Create the trait**

Create `app/Http/Requests/Concerns/ResolvesPublicSiteSubdomain.php`:

```php
<?php

namespace App\Http\Requests\Concerns;

// V2: Public-site endpoints accept a subdomain via the route segment OR an
// X-Site-Subdomain header (analytics endpoints use the header path; the show
// endpoint is route-only). Encapsulates that resolution + lowercasing into one
// call from prepareForValidation().
trait ResolvesPublicSiteSubdomain
{
    /**
     * Merge a normalized `subdomain` key into the request payload, sourced from
     * the matched route parameter and optionally falling back to a header.
     *
     * Pass null (the default) for route-only resolution. Pass a header name to
     * fall back to that header when the route value is missing or empty.
     */
    protected function mergeSubdomainFromRoute(?string $headerName = null): void
    {
        $routeValue = $this->route('subdomain');
        $candidate = is_string($routeValue) ? $routeValue : '';

        if ($candidate === '' && $headerName !== null) {
            $headerValue = $this->header($headerName);
            $candidate = is_string($headerValue) ? trim($headerValue) : '';
        }

        if ($candidate !== '') {
            $this->merge(['subdomain' => strtolower($candidate)]);
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Http/Requests/Concerns/ResolvesPublicSiteSubdomainTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Concerns/ResolvesPublicSiteSubdomain.php tests/Unit/Http/Requests/Concerns/ResolvesPublicSiteSubdomainTest.php
git commit -m "feat(requests): add ResolvesPublicSiteSubdomain trait"
```

---

## Task 6: Migrate phone call sites

**Files:**
- Modify: `app/Http/Requests/Api/Professional/Customer/StoreCustomerRequest.php`
- Modify: `app/Http/Requests/Api/Professional/Customer/UpdateCustomerRequest.php`
- Modify: `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateCustomerRequest.php`
- Modify: `app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php`
- Modify: `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php`

- [ ] **Step 1: `StoreCustomerRequest` — replace inline phone block**

In `app/Http/Requests/Api/Professional/Customer/StoreCustomerRequest.php`, replace the body of `prepareForValidation()` with:

```php
    protected function prepareForValidation(): void
    {
        $this->merge(['source' => $this->input('source', 'manual')]);
        $this->normalizePhones(['phone']);
    }
```

- [ ] **Step 2: `UpdateCustomerRequest` — replace inline phone block**

Replace the body of `prepareForValidation()` in `app/Http/Requests/Api/Professional/Customer/UpdateCustomerRequest.php` with:

```php
    protected function prepareForValidation(): void
    {
        $this->normalizePhones(['phone']);
    }
```

- [ ] **Step 3: `StaffUpdateCustomerRequest` — replace inline phone block**

Replace the body of `prepareForValidation()` in `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateCustomerRequest.php` with:

```php
    protected function prepareForValidation(): void
    {
        $this->normalizePhones(['phone']);
    }
```

- [ ] **Step 4: `UpdateProfessionalRequest` — replace both phone blocks**

In `app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php`, in `prepareForValidation()`, delete the two inline phone blocks (lines 67–78 in current file) and replace them with a single line at the top of the method (just after `$this->normalizeAboutPayload();`):

```php
        $this->normalizePhones(['phone', 'public_contact_number']);
```

- [ ] **Step 5: `StaffUpdateProfessionalRequest` — replace both phone blocks**

In `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php`, in `prepareForValidation()`, delete the two inline phone blocks (lines 65–76 in current file) and replace them with a single line at the top of the method (just after `$this->normalizeAboutPayload();`):

```php
        $this->normalizePhones(['phone', 'public_contact_number']);
```

- [ ] **Step 6: Run targeted feature tests**

Run:
```bash
php artisan test --filter='Professional|Customer'
```
Expected: PASS. If anything fails, the most likely cause is a typo in the merged file — re-read the diff against the original.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Api/Professional/Customer/StoreCustomerRequest.php \
        app/Http/Requests/Api/Professional/Customer/UpdateCustomerRequest.php \
        app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateCustomerRequest.php \
        app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php \
        app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php

git commit -m "refactor(requests): use BaseFormRequest::normalizePhones helper"
```

---

## Task 7: Migrate email call sites + remove duplicate `lowerOrNull`

**Files:**
- Modify: `app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php`
- Modify: `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php`

- [ ] **Step 1: `UpdateProfessionalRequest` — replace email merge + delete `lowerOrNull`**

In `app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php`:

a) Replace the two email blocks in `prepareForValidation()`:
```php
        if ($this->has('primary_email')) {
            $merge['primary_email'] = $this->lowerOrNull($this->input('primary_email'));
        }

        if ($this->has('public_contact_email')) {
            $merge['public_contact_email'] = $this->lowerOrNull($this->input('public_contact_email'));
        }
```
…with a single call (place it after the `normalizePhones` line from Task 6):
```php
        $this->lowercaseEmails(['primary_email', 'public_contact_email']);
```

b) Delete the entire `private function lowerOrNull` method (lines 119–133 in current file).

- [ ] **Step 2: `StaffUpdateProfessionalRequest` — same migration**

In `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php`:

a) Replace the two email blocks (lines 80–86) with:
```php
        $this->lowercaseEmails(['primary_email', 'public_contact_email']);
```

b) Delete the `private function lowerOrNull` method (lines 108–121).

- [ ] **Step 3: Run targeted tests**

Run: `php artisan test --filter=Professional`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php \
        app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php

git commit -m "refactor(requests): use BaseFormRequest::lowercaseEmails and remove duplicated lowerOrNull"
```

---

## Task 8: Migrate `cleanText` call sites

**Files:**
- Modify: `app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php` *(bio field)*
- Modify: `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php` *(bio field — adds parity with self-serve, fixing pre-existing drift)*
- Modify: `app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php` *(title field — drop control-char strip from local code, helper does it)*
- Modify: `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php` *(four `settings.*` text fields — inline loop delegates to `cleanString`)*
- Modify: `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php` *(text + headline + description + cta_label — inline loop delegates to `cleanString`; countdown sanitizer also folded onto `cleanString`)*

- [ ] **Step 1: `UpdateProfessionalRequest` — replace bio block**

Replace the `if ($this->has('bio'))` block (lines 96–102 in current file) with a single line near the top of `prepareForValidation()`:

```php
        $this->cleanText(['bio']);
```

Move it to sit after the `normalizePhones` and `lowercaseEmails` calls.

- [ ] **Step 2: `StaffUpdateProfessionalRequest` — add bio sanitization for parity**

The staff variant currently does NOT sanitize `bio` (verified — its `prepareForValidation` has no bio block), even though `bio` is in its rules and the self-serve variant has been HTML-stripping it. This is a pre-existing self-serve/staff drift that this refactor exists to eliminate.

In `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php`, add this line at the top of `prepareForValidation()`, right after the `normalizePhones` call from Task 6 and `lowercaseEmails` call from Task 7:

```php
        $this->cleanText(['bio']);
```

This is an additive change — the staff endpoint now strips HTML from bio inputs, where previously raw tags would have flowed through to the DB. Stored XSS surface narrows; nothing else changes. Verified by the `Professional` feature suite in Step 6.

- [ ] **Step 3: `StoreLinkBlockRequest` — replace title sanitization**

In `app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php`, remove the `'title' => is_string($title) ? preg_replace(...) : $title,` line from the `$this->merge([...])` block. Then, before the existing `merge` call, add:

```php
        $this->cleanText(['title']);
```

The remaining `merge([...])` call should keep `url`, `icon_key`, `platform`, `handle` — none of those need the cleanText helper.

- [ ] **Step 4: `UpdateSiteRequest` — replace settings.* HTML strip loop**

In `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php`, replace the entire `if (is_array($settings))` block in `prepareForValidation()` (lines 22–30 in current file) with:

```php
        $settings = $this->input('settings');
        if (is_array($settings)) {
            foreach (['hero_title', 'hero_subtitle', 'primary_button_text', 'bio_text'] as $field) {
                if (! array_key_exists($field, $settings) || ! is_string($settings[$field])) {
                    continue;
                }
                $settings[$field] = static::cleanString($settings[$field]);
            }
            $merge['settings'] = $settings;
        }
```

The `is_string` guard stays so non-string values pass through untouched (matches existing behaviour). The transform itself delegates to `static::cleanString` — single source of truth with the helper added in Task 3. The flat `cleanText(array $keys)` signature still doesn't fit nested keys, but the duplication that would otherwise live here is gone.

- [ ] **Step 5: `UpsertSectionBlockRequest` — replace inline strip_tags blocks**

In `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php`:

Replace the `// normalize text` block (lines 141–147) AND the newsletter-copy block immediately following (lines 149–163) with a single combined loop, after the existing `block_type` and `publication_state` merges:

```php
        $settings = $this->input('settings', []);
        if (is_array($settings)) {
            $settingsChanged = false;
            foreach (['text', 'headline', 'description', 'cta_label'] as $field) {
                if (! array_key_exists($field, $settings) || ! is_string($settings[$field])) {
                    continue;
                }
                $settings[$field] = static::cleanString($settings[$field]);
                $settingsChanged = true;
            }
            if ($settingsChanged) {
                $this->merge(['settings' => $settings]);
            }
        }
```

This replaces both the `text` block and the `headline / description / cta_label` block in one pass; the transform delegates to `static::cleanString` for parity with Task 8 Step 4 and Task 9.

Then update the `sanitizeCountdownSettings` private method (lines 180–219). Replace the inner `$clean` closure body so it delegates the string-cleaning to `cleanString` while preserving the existing behaviour of leaving non-string values as-is:

```php
        $clean = static function (mixed $value): mixed {
            return is_string($value) ? static::cleanString($value) : $value;
        };
```

Note this also gives countdown fields the control-char strip (existing closure used `trim(strip_tags($value))` only) — see Behaviour Note 2.

The countdown sanitizer's outer scoping (only when `block_type === 'countdown'`) and nested-path walks (`states.*.cta.label` etc.) stay as-is — those genuinely need bespoke traversal.

- [ ] **Step 6: Run targeted feature tests**

Run:
```bash
php artisan test --filter='Site|Block|Section|Professional'
```
Expected: PASS. Pay particular attention to any test that asserts `''` for a previously-empty text field — those will need updating to assert `null` (per the behaviour note at the top of this plan). The `Professional` filter also covers the new bio sanitization on `StaffUpdateProfessionalRequest` from Step 2.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php \
        app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php \
        app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php \
        app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php \
        app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php

git commit -m "refactor(requests): use BaseFormRequest::cleanText/cleanString and add staff bio parity"
```

---

## Task 9: Delegate `ValidatesProfessionalAbout::cleanStringOrNull` to `cleanString`

**Files:**
- Modify: `app/Http/Requests/Concerns/ValidatesProfessionalAbout.php`

- [ ] **Step 1: Replace `cleanStringOrNull` body with a delegation to `cleanString`**

The trait uses `cleanStringOrNull` to clean nested entries (`credentials.*.title`, `credentials.*.issuer`, `experience.*.role`, `experience.*.place`, `experience.*.description`). The key-array `cleanText(array $keys)` signature does not apply to nested array values, but the value-level `cleanString` helper from Task 3 does — exactly the reason it was lifted out separately.

In `app/Http/Requests/Concerns/ValidatesProfessionalAbout.php`, replace the entire `cleanStringOrNull` method (lines 100–108) with:

```php
    /**
     * Delegates to BaseFormRequest::cleanString — kept here as a thin alias so
     * the trait's nested-walks (credentials.*, experience.*) can stay readable.
     * The trait is only ever used by classes that extend BaseFormRequest, so
     * static::cleanString resolves through the consuming class.
     */
    private function cleanStringOrNull(mixed $value): ?string
    {
        return static::cleanString(is_string($value) ? $value : null);
    }
```

This collapses one of the four duplicate copies of the strip-tags + control-chars + null-coerce transform onto the single `cleanString` source of truth in `BaseFormRequest`. The behaviour upgrade (control-char strip — see Behaviour Note 2) lands here as a side-effect of the delegation.

- [ ] **Step 2: Run the about-payload tests**

Run: `php artisan test --filter='About|Professional'`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/Concerns/ValidatesProfessionalAbout.php
git commit -m "refactor(requests): delegate ValidatesProfessionalAbout::cleanStringOrNull to cleanString"
```

---

## Task 10: Migrate subdomain call sites to the new trait

**Files:**
- Modify: `app/Http/Requests/Api/PublicSite/Analytics/PageviewRequest.php`
- Modify: `app/Http/Requests/Api/PublicSite/Analytics/ClickRequest.php`
- Modify: `app/Http/Requests/Api/PublicSite/PublicSiteShowRequest.php`

- [ ] **Step 1: `PageviewRequest` — adopt the trait**

Replace the file contents with:

```php
<?php

namespace App\Http\Requests\Api\PublicSite\Analytics;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ResolvesPublicSiteSubdomain;
use Illuminate\Validation\Rule;

// V2: Validates public pageview-tracking events — requires site identification via UUID or subdomain, plus optional session, visitor, and UTM fields.
class PageviewRequest extends BaseFormRequest
{
    use ResolvesPublicSiteSubdomain;

    protected function prepareForValidation(): void
    {
        $this->mergeSubdomainFromRoute('X-Site-Subdomain');
    }

    public function rules(): array
    {
        return [
            'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('sites', 'id')],
            'subdomain' => ['required_without:site_id', 'string', 'max:63'],
            'session_id' => ['nullable', 'uuid'],
            'visitor_id' => ['nullable', 'uuid'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 2: `ClickRequest` — adopt the trait**

Replace the file contents with:

```php
<?php

namespace App\Http\Requests\Api\PublicSite\Analytics;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ResolvesPublicSiteSubdomain;
use Illuminate\Validation\Rule;

// V2: Validates public click-tracking events — requires a block ID with site identification via UUID or subdomain, plus optional session and UTM fields.
class ClickRequest extends BaseFormRequest
{
    use ResolvesPublicSiteSubdomain;

    protected function prepareForValidation(): void
    {
        $this->mergeSubdomainFromRoute('X-Site-Subdomain');
    }

    public function rules(): array
    {
        return [
            'block_id' => ['required', 'uuid', Rule::exists('blocks', 'id')],
            'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('sites', 'id')],
            'subdomain' => ['required_without:site_id', 'string', 'max:63'],
            'session_id' => ['nullable', 'uuid'],
            'visitor_id' => ['nullable', 'uuid'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 3: `PublicSiteShowRequest` — adopt the trait (route-only)**

Replace the file contents with:

```php
<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ResolvesPublicSiteSubdomain;

// V2: Validates public site lookup by subdomain — normalizes to lowercase and enforces alphanumeric-hyphen format with a 63-char limit.
class PublicSiteShowRequest extends BaseFormRequest
{
    use ResolvesPublicSiteSubdomain;

    protected function prepareForValidation(): void
    {
        $this->mergeSubdomainFromRoute(); // route-only: no header fallback
    }

    public function rules(): array
    {
        return [
            'subdomain' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9-]+$/i'],
        ];
    }
}
```

- [ ] **Step 4: Run targeted tests**

Run: `php artisan test --filter='Public|Pageview|Click|Analytics'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Api/PublicSite/Analytics/PageviewRequest.php \
        app/Http/Requests/Api/PublicSite/Analytics/ClickRequest.php \
        app/Http/Requests/Api/PublicSite/PublicSiteShowRequest.php

git commit -m "refactor(requests): use ResolvesPublicSiteSubdomain trait in public-site requests"
```

---

## Task 11: Final verification

**Files:**
- None modified.

- [ ] **Step 1: Apply Pint**

Run: `php artisan pint`
Expected: any whitespace/import-order tweaks applied. If Pint touches files, stage + amend or follow with a `style:` commit per project preference.

- [ ] **Step 2: Run the full suite**

Run: `composer test`
Expected: PASS — 0 failures, 0 errors.

- [ ] **Step 3: Spot-check Nightwatch after deploy**

(For the engineer who reviews / merges the PR — not a blocking task in this session.) After this lands on the next deploy, check Nightwatch for any new exceptions on:
- `POST /api/professional` (UpdateProfessionalRequest)
- `POST /api/customers` (StoreCustomerRequest)
- `POST /api/site` (UpdateSiteRequest)
- `POST /api/sites/{subdomain}/pageview` (PageviewRequest)

If a regression surfaces, the most likely culprit is the empty-string-to-null change called out in the Behaviour Notes; check whether any consumer (Hydrogen client, frontend Resource transformer) treats `null` differently from `''`.

---

## Self-Review Notes

- **Spec coverage:** all four extraction targets (#2 phone, #3 cleanText/cleanString, #4 lowercaseEmails, #5 subdomain trait) are implemented in Tasks 2–5; migrated in Tasks 6–10; verified in Task 11.
- **Lift target #4 (`lowerOrNull` duplication)** is collapsed by Task 7 — both copies deleted, both call sites delegate to `lowercaseEmails`.
- **`cleanString` single source of truth.** All four pre-existing copies of the strip-tags + control-chars + null-coerce transform — `cleanText` (new), `ValidatesProfessionalAbout::cleanStringOrNull`, `UpdateSiteRequest`'s inline loop, `UpsertSectionBlockRequest`'s inline loop and countdown closure — collapse onto `BaseFormRequest::cleanString` after this PR. No drift surface remains.
- **Self-serve / staff parity:** `StaffUpdateProfessionalRequest::bio` is brought into line with `UpdateProfessionalRequest::bio` in Task 8 Step 2. Pre-existing drift, fixed in passing because we are already editing both files and the audit motivation explicitly cites this kind of divergence.
- **Behaviour shifts** are documented at the top of the plan (Behaviour Notes 1, 2, 3) and explicitly tested. No silent semantics change.
- **Out-of-scope items** (`PublicEnquiryRequest` phone, `PublicWaitlistSignupRequest` phone, `UpdateSiteRequest` subdomain) are listed under Behaviour Notes so the next contributor knows why they look untouched.
- **No placeholders.** Every code block is the exact final form, including imports.
- **Type / naming consistency:** `normalizePhones`, `cleanText`, `cleanString`, `lowercaseEmails`, `mergeSubdomainFromRoute` are the single source of truth for these names — used identically across tests, helper definitions, and call sites.
- **PR1/PR2 ordering:** noted at the top of the plan. Both modify `BaseFormRequest.php` on disjoint regions; merge-order safe in either direction.
- **Commit cadence:** ten commits, each independently revertable, mirror the Tasks. Easy bisect if anything regresses.
