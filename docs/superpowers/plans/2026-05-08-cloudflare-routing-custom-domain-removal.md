# Cloudflare Routing + Custom Domain Removal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the URL architecture migration. Drop legacy URL fields from `/api/me`, remove custom-domain capability entirely, auto-provision brand CNAMEs at signup, prepare and deploy the Cloudflare Worker for affiliate redirects.

**Tech Stack:** PHP 8.5 / Laravel 12, PostgreSQL (Supabase), Pest 4, Cloudflare Workers + KV.

**Pre-existing context** (already on `development-v2` from previous session — do NOT redo):
- `core.professionals.partna_url` and `brand.brand_partner_links.site_url` columns + triggers (commit `b7eaeff`)
- `affiliate_page_url` direction fix (commit `85ffae0`)
- `CloudflareKvService`, `SyncSubdomainToKvJob`, `BrandPartnerLinkObserver` and observer wiring (commit `f5bedf6`)
- Cloudflare Worker code in `cloudflare-worker/` (commit `e462656`)

**Source plan:** `docs/superpowers/plans/2026-05-08-url-naming-refactor.md` covered everything up to commit `dc1bbd6`. This plan covers the remaining backend cleanup + Cloudflare deploy. Frontend cleanup is a separate workstream not in this plan.

**Pre-flight:**
- `git fetch && git pull origin development-v2`
- `git log --oneline -10` should show `b3be0b5` or later as HEAD
- `composer test` should pass cleanly (1654 passed, 27 skipped baseline)
- Branch off: `git checkout -b feat/cloudflare-routing-cleanup` from `development-v2`

**No live customers** — Josh confirmed pre-beta state. We can ship breaking changes without coordination, since the dashboard frontend isn't yet using the new fields. After this plan ships, frontend devs will switch consumers in a parallel PR.

---

## Phase 1 — Backend code cleanup

### Task 1: Drop legacy URL fields from `/api/me` (§4.1)

**Files:**
- `app/Http/Controllers/Api/Professional/ProfessionalController.php`
- `tests/` — find and update any tests referencing the dropped keys

**What to remove:**

In `ProfessionalController::show()`:
- Line ~75: drop `'storefront_base_url' => 'https://'.$pro->site->subdomain.'.'.config(...)` from the `site` payload
- Line ~76: drop `'affiliate_page_url' => $pro->professional_type === 'brand' ? $pro->partna_url : $primaryAffiliateSiteUrl` from the `site` payload
- Line ~43: drop `$primaryAffiliateSiteUrl = null;` initialization
- Lines ~60-63: drop the `BrandPartnerLink::query()->where(...)->value('site_url')` block that resolved `$primaryAffiliateSiteUrl`
- Top of file: drop `use App\Models\Core\Professional\BrandPartnerLink;` if no longer needed elsewhere in the controller

The remaining `site` payload should be `{id, subdomain, is_published, settings}` only. Frontends now read URLs from `professional.partna_url` and per-link `site_url` (already exposed on the affiliate listing endpoint).

- [ ] **Step 1.1:** Remove the two keys, the `$primaryAffiliateSiteUrl` resolution block, and the unused import.
- [ ] **Step 1.2:** Run `php artisan test --compact tests/Feature/Professional/` and fix any tests that asserted on the removed keys.
- [ ] **Step 1.3:** `grep -rn "storefront_base_url\|affiliate_page_url" tests/` — clean up any remaining test references.
- [ ] **Step 1.4:** `vendor/bin/pint --dirty`.

**Acceptance:**
- `composer test` passes
- `grep -rn "storefront_base_url\|affiliate_page_url" app/` returns zero hits
- Hitting `/api/me` for a brand or affiliate returns `site` without those keys

---

### Task 2: Fix `BrandAffiliateListingUrlTest` documentation (§4.6)

**File:** `tests/Feature/Brand/BrandAffiliateListingUrlTest.php`

The test at line 103 sets `$expectedUrl = 'https://jane.partna.au/brand-co';` — wrong direction. Should be `'https://brand-co.partna.au/jane'` to match the trigger's actual output (`brand.partna.au/affiliate`). The test currently passes because it asserts a manually-seeded value (no triggers in SQLite test env), but reads as misleading documentation.

- [ ] **Step 2.1:** Change line 103 to `$expectedUrl = 'https://brand-co.partna.au/jane';`
- [ ] **Step 2.2:** Run `php artisan test --compact tests/Feature/Brand/BrandAffiliateListingUrlTest.php` — should still pass.

**Acceptance:** Test passes with the corrected URL direction.

---

### Task 3: Drop `domain_mode` from internal Shopify-app endpoints (§4.5)

**Files (need to discover):**
- `app/Http/Controllers/Api/Internal/EmbeddedSetupController.php` — has `setupDomain` and `getConfiguration` methods
- Any related Form Request

**What to do:**

- [ ] **Step 3.1:** Find every reference to `domain_mode` in `app/Http/Controllers/Api/Internal/`. Use `grep -rn "domain_mode" app/`.
- [ ] **Step 3.2:** In the configuration response (likely `EmbeddedSetupController::getConfiguration` or similar), remove `domain_mode` from the response array.
- [ ] **Step 3.3:** In the `setupDomain` action, if it accepts `mode` in the payload, hardcode it to platform behavior. Reject `custom` mode if the request includes it (treat as 422 or just ignore — match codebase patterns).
- [ ] **Step 3.4:** Update tests in `tests/Feature/` that touch these endpoints.
- [ ] **Step 3.5:** `vendor/bin/pint --dirty`.

**Acceptance:**
- `grep -rn "domain_mode" app/` returns zero hits in code paths under `app/Http/`
- `composer test` passes
- The Shopify embedded app's configuration page won't see `domain_mode` in API responses

---

### Task 4: Drop custom-domain capability entirely (§4.2)

This is the most involved task. Custom domain support is being removed: 6 DB columns, the trigger branch, the observer cascade we added in `f5bedf6`, and any model/resource references.

**Sub-task 4A: Database migration**

Create one migration file: `supabase/migrations/20260509100000_drop_custom_domain.sql`.

- [ ] **Step 4.1:** Create the migration with this content:

```sql
-- 20260509100000_drop_custom_domain.sql
-- Removes custom-domain capability from brand_store_settings.
-- The trigger that reacted to custom_domain changes is dropped first; the URL
-- composition function is simplified to use only site.sites.subdomain.

BEGIN;

-- 1. Drop the trigger that fires on custom_domain changes.
DROP TRIGGER IF EXISTS store_settings_url_sync_aiu ON brand.brand_store_settings;
DROP FUNCTION IF EXISTS brand.trg_store_settings_url_sync();

-- 2. Simplify compute_professional_url — remove the custom_domain branch.
CREATE OR REPLACE FUNCTION site.compute_professional_url(p_professional_id uuid)
RETURNS text LANGUAGE plpgsql STABLE AS $$
DECLARE
    v_subdomain text;
BEGIN
    SELECT s.subdomain INTO v_subdomain
      FROM site.sites s
     WHERE s.professional_id = p_professional_id;

    IF v_subdomain IS NULL THEN
        RETURN NULL;
    END IF;

    RETURN 'https://' || v_subdomain || '.partna.au';
END;
$$;

-- 3. Drop the custom-domain columns.
ALTER TABLE brand.brand_store_settings
    DROP COLUMN IF EXISTS custom_domain,
    DROP COLUMN IF EXISTS custom_domain_verified_at,
    DROP COLUMN IF EXISTS custom_domain_tls_provisioned_at,
    DROP COLUMN IF EXISTS domain_mode,
    DROP COLUMN IF EXISTS domain_wizard_complete,
    DROP COLUMN IF EXISTS domain_txt_confirmed;

COMMIT;
```

- [ ] **Step 4.2:** Apply via Supabase MCP `apply_migration` (project_id is `glncumufgaqcmqhzwrxm`).

**Sub-task 4B: Model and resource cleanup**

- [ ] **Step 4.3:** Edit `app/Models/Retail/BrandStoreSettings.php`:
  - Remove `custom_domain`, `custom_domain_verified_at`, `custom_domain_tls_provisioned_at`, `domain_mode`, `domain_wizard_complete`, `domain_txt_confirmed` from `$fillable` (lines 27-31 area)
  - Remove same fields from `$casts` (lines 46-47 area)
  - Remove the seeded-defaults block at lines 92-96 that initializes `domain_mode => 'platform'`, `custom_domain => null`, etc.
  - If there's a `storefrontBaseUrl()` accessor method, remove it (callers should use `partna_url` from the related Professional)

- [ ] **Step 4.4:** Edit `app/Http/Resources/BrandStoreSettingsResource.php`:
  - Remove any keys that reference custom_domain or domain_mode columns

- [ ] **Step 4.5:** Read `app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php` and `app/Services/Professional/BrandPartnerSiteSettingsSync.php`. The original plan claimed these had `sidest.co` fallbacks — they don't anymore (cleaned in earlier rebrand). But check for any custom_domain reads/writes and remove them.

**Sub-task 4C: Remove dead observer code**

Commit `f5bedf6` added a `syncKvIfDomainChanged()` method to `BrandStoreSettingsObserver`. Once custom_domain columns are gone, that method's `wasChanged('custom_domain')` guards always return false — it's dead code.

- [ ] **Step 4.6:** Edit `app/Observers/Retail/BrandStoreSettingsObserver.php`:
  - Remove the `syncKvIfDomainChanged()` private method entirely (~lines 52-85)
  - Remove the `$this->syncKvIfDomainChanged($settings);` call from `saved()` (~line 24)
  - Remove the now-unused imports: `use App\Jobs\Cloudflare\SyncSubdomainToKvJob;` and `use App\Models\Core\Professional\BrandPartnerLink;`

**Sub-task 4D: Embedded controller cleanup**

`app/Http/Controllers/Api/Internal/EmbeddedSetupController.php` has a `setupDomain` flow that wrote to custom_domain fields. After Task 3 (`domain_mode` removal) and the column drop, parts of this controller may write to columns that no longer exist.

- [ ] **Step 4.7:** Read `EmbeddedSetupController::setupDomain()` and `provisionDomainTxt()`. Remove any writes to the dropped columns. Keep the TXT-record-provisioning logic intact (Shopify Hydrogen flow still needs it). The TXT record itself is unrelated to the dropped DB columns.

**Sub-task 4E: Test cleanup**

- [ ] **Step 4.8:** `grep -rn "custom_domain\|domain_mode\|domain_wizard_complete\|domain_txt_confirmed" tests/` — find every test reference. For each:
  - If the test asserts the field's behavior, delete those assertions
  - If the test seeds data with those fields, remove them from the seed
  - If the test was specifically testing custom-domain capability, delete the test
- [ ] **Step 4.9:** `composer test` — all tests should pass.
- [ ] **Step 4.10:** `vendor/bin/pint --dirty`.

**Acceptance:**
- Supabase migration applied; columns gone; trigger gone; function simplified
- `grep -rn "custom_domain\|domain_mode\|domain_wizard_complete\|domain_txt_confirmed" app/` returns zero hits
- `composer test` passes (1654 baseline minus any tests deleted with custom_domain capability)
- `BrandStoreSettingsObserver` no longer has `syncKvIfDomainChanged`

---

### Task 5: Auto-provision brand DNS at signup (§4.3)

**Goal:** When a brand bootstraps, automatically create the Cloudflare CNAME `<brand>.partna.au → shops.myshopify.com` (DNS-only). When a brand renames their subdomain, retire the old CNAME and create the new one. Affiliates need NO DNS work — the wildcard `*.partna.au` handles them via the Worker.

**Design (decided during planning):**
- Two queued jobs: `ProvisionBrandDnsJob` (idempotent upsert) and `RetireBrandDnsJob` (delete by name lookup).
- Dispatched from `SiteObserver` — provision on site create or subdomain change; retire the old subdomain only on subdomain change.
- No new DB columns. `CloudflareDnsService::upsertCname()` is idempotent; `CloudflareDnsService::findRecord()` + `deleteRecord()` handle retirement without needing a stored record ID. (Add `cf_cname_record_id` later if API rate limits become an issue.)
- Old subdomain captured via `updating` event handler stashing it on a transient model property; consumed in `saved`.

**Sub-task 5A: Create `ProvisionBrandDnsJob`**

- [ ] **Step 5.1:** Create `app/Jobs/Cloudflare/ProvisionBrandDnsJob.php`:

```php
<?php

namespace App\Jobs\Cloudflare;

use App\Models\Core\Professional\Professional;
use App\Services\Cloudflare\CloudflareDnsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Provisions the Cloudflare CNAME for a brand's subdomain → shops.myshopify.com (DNS-only).
// Idempotent — safe to dispatch multiple times. No-op for non-brand professionals or
// professionals without a site row.
class ProvisionBrandDnsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $professionalId) {}

    public function handle(CloudflareDnsService $dns): void
    {
        $pro = Professional::query()->with('site')->find($this->professionalId);

        if (! $pro || ! $pro->isBrand()) {
            return;
        }

        $subdomain = $pro->site?->subdomain;
        if (! $subdomain) {
            Log::info('ProvisionBrandDnsJob: brand has no site row yet, skipping', [
                'professional_id' => $this->professionalId,
            ]);

            return;
        }

        // upsertCname is idempotent — safe to call repeatedly.
        // Oxygen requires DNS-only (proxied=false).
        $dns->upsertCname($subdomain, 'shops.myshopify.com', false);
    }
}
```

**Sub-task 5B: Create `RetireBrandDnsJob`**

- [ ] **Step 5.2:** Create `app/Jobs/Cloudflare/RetireBrandDnsJob.php`:

```php
<?php

namespace App\Jobs\Cloudflare;

use App\Services\Cloudflare\CloudflareDnsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Removes the Cloudflare CNAME for a retired subdomain. Looks up the record by name
// (no stored record ID needed). Used when a brand renames their subdomain.
class RetireBrandDnsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $subdomain) {}

    public function handle(CloudflareDnsService $dns): void
    {
        if ($this->subdomain === '') {
            return;
        }

        $fqdn = $this->subdomain . '.' . config('partna.public_domain', 'partna.au');
        $record = $dns->findRecord('CNAME', $fqdn);

        if (! $record || ! isset($record['id'])) {
            Log::info('RetireBrandDnsJob: no record found for subdomain (already gone)', [
                'subdomain' => $this->subdomain,
                'fqdn' => $fqdn,
            ]);

            return;
        }

        $dns->deleteRecord((string) $record['id']);
    }
}
```

**Sub-task 5C: Verify `CloudflareDnsService` API surface matches**

- [ ] **Step 5.3:** Read `app/Services/Cloudflare/CloudflareDnsService.php`. Confirm signatures of `upsertCname()`, `findRecord()`, `deleteRecord()`. If signatures differ from what's used in the jobs above, adjust the job code to match.
  - `upsertCname` likely takes `(string $subdomain, string $target, bool $proxied)` — verify
  - `findRecord` likely takes `(string $type, string $name)` — verify
  - `deleteRecord` likely takes `(string $recordId)` — verify

**Sub-task 5D: Wire `SiteObserver` to dispatch the jobs**

The current `SiteObserver` (in `app/Observers/Core/SiteObserver.php`) already dispatches `SyncSubdomainToKvJob` on subdomain change with brand cascade. Extend it to also dispatch the DNS jobs.

- [ ] **Step 5.4:** Add to `SiteObserver`:
  - Add `use App\Jobs\Cloudflare\ProvisionBrandDnsJob;` and `use App\Jobs\Cloudflare\RetireBrandDnsJob;`
  - Add `use App\Models\Core\Professional\Professional;` if not already imported
  - Add an `updating()` method to capture the old subdomain when changing:

```php
public function updating(Site $site): void
{
    if ($site->isDirty('subdomain')) {
        // Stash on the model so saved() can dispatch retirement of the old CNAME.
        // afterCommit on the observer ensures we never retire DNS for a save that rolls back.
        $site->_oldSubdomainPendingRetire = $site->getOriginal('subdomain');
    }
}
```

  - In `saved()`, inside the existing `if ($site->wasRecentlyCreated || $site->wasChanged('subdomain'))` block, after the existing KV sync dispatches, add:

```php
// Provision DNS for brand sites only. Affiliates use the wildcard.
$pro = Professional::query()->find((string) ($site->professional_id ?? ''));
if ($pro?->isBrand()) {
    try {
        ProvisionBrandDnsJob::dispatch((string) $site->professional_id);
    } catch (\Throwable $e) {
        Log::warning('SiteObserver: ProvisionBrandDnsJob dispatch failed', [
            'site_id' => $site->id,
            'professional_id' => $site->professional_id,
            'message' => $e->getMessage(),
        ]);
    }
}

// If the subdomain changed, retire the old CNAME (only meaningful for brands;
// for affiliates it's a no-op since there's no per-affiliate CNAME).
if (isset($site->_oldSubdomainPendingRetire) && $pro?->isBrand()) {
    try {
        RetireBrandDnsJob::dispatch((string) $site->_oldSubdomainPendingRetire);
    } catch (\Throwable $e) {
        Log::warning('SiteObserver: RetireBrandDnsJob dispatch failed', [
            'site_id' => $site->id,
            'old_subdomain' => $site->_oldSubdomainPendingRetire,
            'message' => $e->getMessage(),
        ]);
    }
    unset($site->_oldSubdomainPendingRetire);
}
```

**Sub-task 5E: Backfill command for existing brands**

- [ ] **Step 5.5:** Create `app/Console/Commands/Partna/BackfillBrandDnsCommand.php`:

```php
<?php

namespace App\Console\Commands\Partna;

use App\Jobs\Cloudflare\ProvisionBrandDnsJob;
use App\Models\Core\Professional\Professional;
use Illuminate\Console\Command;

class BackfillBrandDnsCommand extends Command
{
    protected $signature = 'partna:backfill-brand-dns
                            {--queue : Dispatch via the queue (default: synchronous)}';

    protected $description = 'Provisions Cloudflare CNAME for every brand professional with a site row. Idempotent.';

    public function handle(): int
    {
        $brands = Professional::query()
            ->where('professional_type', 'brand')
            ->whereHas('site')
            ->pluck('id');

        $this->info("Found {$brands->count()} brand(s) to backfill.");

        $useQueue = (bool) $this->option('queue');

        foreach ($brands as $id) {
            if ($useQueue) {
                ProvisionBrandDnsJob::dispatch((string) $id);
                $this->line("  queued: {$id}");
            } else {
                ProvisionBrandDnsJob::dispatchSync((string) $id);
                $this->line("  done:   {$id}");
            }
        }

        $this->info('Backfill complete.');

        return self::SUCCESS;
    }
}
```

**Sub-task 5F: Tests**

Per project convention, write Pest tests. Mock `CloudflareDnsService` to avoid hitting real Cloudflare in tests.

- [ ] **Step 5.6:** Create `tests/Unit/Jobs/Cloudflare/ProvisionBrandDnsJobTest.php`:

```php
<?php

use App\Jobs\Cloudflare\ProvisionBrandDnsJob;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Cloudflare\CloudflareDnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('upserts a DNS-only CNAME for brand professionals with a site', function () {
    $brand = Professional::factory()->create(['professional_type' => 'brand']);
    Site::factory()->create(['professional_id' => $brand->id, 'subdomain' => 'evostudio']);

    $dns = mock(CloudflareDnsService::class);
    $dns->shouldReceive('upsertCname')
        ->once()
        ->with('evostudio', 'shops.myshopify.com', false);

    (new ProvisionBrandDnsJob((string) $brand->id))->handle($dns);
});

it('no-ops for non-brand professionals', function () {
    $affiliate = Professional::factory()->create(['professional_type' => 'influencer']);
    Site::factory()->create(['professional_id' => $affiliate->id, 'subdomain' => 'jane']);

    $dns = mock(CloudflareDnsService::class);
    $dns->shouldNotReceive('upsertCname');

    (new ProvisionBrandDnsJob((string) $affiliate->id))->handle($dns);
});

it('no-ops when brand has no site row', function () {
    $brand = Professional::factory()->create(['professional_type' => 'brand']);

    $dns = mock(CloudflareDnsService::class);
    $dns->shouldNotReceive('upsertCname');

    (new ProvisionBrandDnsJob((string) $brand->id))->handle($dns);
});
```

- [ ] **Step 5.7:** Create `tests/Unit/Jobs/Cloudflare/RetireBrandDnsJobTest.php`:

```php
<?php

use App\Jobs\Cloudflare\RetireBrandDnsJob;
use App\Services\Cloudflare\CloudflareDnsService;

use function Pest\Laravel\mock;

it('finds and deletes the CNAME for the retired subdomain', function () {
    $dns = mock(CloudflareDnsService::class);
    $dns->shouldReceive('findRecord')
        ->once()
        ->with('CNAME', 'oldname.partna.au')
        ->andReturn(['id' => 'rec-123', 'name' => 'oldname.partna.au']);
    $dns->shouldReceive('deleteRecord')
        ->once()
        ->with('rec-123');

    (new RetireBrandDnsJob('oldname'))->handle($dns);
});

it('no-ops when the record does not exist', function () {
    $dns = mock(CloudflareDnsService::class);
    $dns->shouldReceive('findRecord')
        ->once()
        ->with('CNAME', 'gone.partna.au')
        ->andReturn(null);
    $dns->shouldNotReceive('deleteRecord');

    (new RetireBrandDnsJob('gone'))->handle($dns);
});
```

- [ ] **Step 5.8:** Run `php artisan test --compact tests/Unit/Jobs/Cloudflare/` — both should pass.

**Sub-task 5G: Failure handling note**

The existing `CloudflareDnsService` likely fails-open in dev mode (no API token). Verify by reading its constructor — if it doesn't, the jobs will throw on every dev run. If needed, mirror the pattern from `CloudflareKvService` (graceful no-op when unconfigured).

- [ ] **Step 5.9:** Read `CloudflareDnsService.__construct()`. If it doesn't fail-open on missing config, add a `$configured` flag and early-return in `upsertCname` / `findRecord` / `deleteRecord`. Match the pattern in `CloudflareKvService.php` (lines 25-31).

**Acceptance:**
- Both jobs created with tests passing
- `SiteObserver` dispatches `ProvisionBrandDnsJob` on brand site create/subdomain change
- `SiteObserver` dispatches `RetireBrandDnsJob` for the old subdomain on subdomain change (brands only)
- Backfill artisan command exists and runs without errors
- Full suite passes: `composer test`

---

## Phase 2 — Cloudflare Worker tweaks

### Task 6: Apply pre-deploy Worker tweaks

**File:** `cloudflare-worker/src/index.js`

Two tweaks before deploy: drop the path-merge logic, and add a real Cache-Control header on redirects (do it now, not as TODO — the deferred version permanently mis-routes browsers after a primary-brand swap).

- [ ] **Step 6.1:** Open `cloudflare-worker/src/index.js`. Find the affiliate redirect block (~lines 71-86):

```js
if (entry.type === "affiliate" && typeof entry.redirect === "string") {
  const target = new URL(entry.redirect);
  // Append the original path/query to the affiliate's brand path so deep links work:
  //   jane.partna.au/products/x?foo=1  →  brand.partna.au/jane/products/x?foo=1
  const basePath = target.pathname.replace(/\/+$/, "");
  const incomingPath = url.pathname.replace(/^\/+/, "/");
  target.pathname = (basePath + incomingPath).replace(/\/{2,}/g, "/") || "/";
  target.search = url.search;
  return Response.redirect(target.toString(), 301);
}
```

Replace with:

```js
if (entry.type === "affiliate" && typeof entry.redirect === "string") {
  // Drop incoming path/query — Hydrogen only has $affiliateSlug.tsx (no nested
  // affiliate routes), so preserving paths produces 404s. Redirect cleanly to
  // the affiliate's brand-side page.
  return new Response(null, {
    status: 301,
    headers: {
      Location: entry.redirect,
      // Without this, browsers cache 301s indefinitely. A primary-brand swap
      // would leave stale redirects in client caches until users manually clear.
      "Cache-Control": "max-age=0, must-revalidate",
    },
  });
}
```

- [ ] **Step 6.2:** Quick sanity check: `cd cloudflare-worker && npx wrangler --version` (should print a version, not error). If not, skip — we'll install when deploying.

**Acceptance:**
- Path-merge code removed
- Redirects use `new Response(...)` with explicit Cache-Control header
- File compiles when Wrangler runs (deferred to Task 8)

---

## Phase 3 — Cloudflare manual setup (Josh has to do parts of this)

### Task 7: Manual Cloudflare dashboard setup

These steps require the Cloudflare dashboard and `wrangler` CLI auth — they can't be automated from Laravel. Run them in order.

- [ ] **Step 7.1:** `cd cloudflare-worker && npm install` (one-time wrangler install)
- [ ] **Step 7.2:** `npx wrangler login` (opens browser for OAuth — Josh approves)
- [ ] **Step 7.3:** Get the account ID — Cloudflare dashboard → right sidebar of any zone → "Account ID". Save for env var.
- [ ] **Step 7.4:** Create production KV namespace:
  ```
  npx wrangler kv:namespace create SUBDOMAIN_KV
  ```
  Copy the printed `id` value.
- [ ] **Step 7.5:** Create preview KV namespace (used by `wrangler dev`):
  ```
  npx wrangler kv:namespace create SUBDOMAIN_KV --preview
  ```
  Copy the printed `id` value.
- [ ] **Step 7.6:** Edit `cloudflare-worker/wrangler.toml` — paste the two IDs:
  ```toml
  [[kv_namespaces]]
  binding = "SUBDOMAIN_KV"
  id = "<paste production id>"
  preview_id = "<paste preview id>"
  ```
- [ ] **Step 7.7:** In Cloudflare dashboard → DNS → Records on the `partna.au` zone, add:
  - Type: `A`
  - Name: `*`
  - IPv4: `192.0.2.1` (RFC 5737 documentation IP — Worker intercepts before reaching it)
  - Proxy status: **Proxied** (orange cloud)
- [ ] **Step 7.8:** Verify the existing `CLOUDFLARE_API_TOKEN` has Workers KV: Edit permission. If not, create a new token at My Profile → API Tokens with: Zone: DNS: Edit (scoped to `partna.au`) + Account: Workers KV Storage: Edit. Save the new token.
- [ ] **Step 7.9:** Set the env vars on Laravel Cloud production environment (and any staging):
  - `CLOUDFLARE_ACCOUNT_ID` = the value from step 7.3
  - `CLOUDFLARE_KV_NAMESPACE_ID` = the production namespace `id` from step 7.4
  - `CLOUDFLARE_API_TOKEN` = updated token from step 7.8 (if rotated)

**Acceptance:**
- Both KV namespaces created
- Wildcard A record added to DNS, proxied
- Env vars set on Laravel Cloud
- `wrangler.toml` has both IDs filled in

---

### Task 8: Deploy the Worker

- [ ] **Step 8.1:** From `cloudflare-worker/`:
  ```
  npm run deploy
  ```
  Should print `Deployed partna-subdomain-router triggers` with the route `*.partna.au/*`.
- [ ] **Step 8.2:** Confirm in Cloudflare dashboard → Workers & Pages → `partna-subdomain-router` → Triggers — route is bound and active.
- [ ] **Step 8.3:** Smoke-test by manually seeding KV:
  ```
  npx wrangler kv:key put --binding SUBDOMAIN_KV "test-affiliate" \
    '{"type":"affiliate","redirect":"https://example.com/test-affiliate"}'
  curl -I https://test-affiliate.partna.au
  # Expect: HTTP/2 301, location: https://example.com/test-affiliate, cache-control: max-age=0, must-revalidate
  ```
  Then clean up: `npx wrangler kv:key delete --binding SUBDOMAIN_KV "test-affiliate"`.

**Acceptance:**
- Worker deployed and route active
- Manual smoke test passes (301 with correct Location and Cache-Control headers)

---

## Phase 4 — End-to-end verification

### Task 9: Verify the whole pipeline works

- [ ] **Step 9.1:** Run `composer test` — full suite passes.
- [ ] **Step 9.2:** Run `php artisan partna:backfill-brand-dns --queue` — queues a job per existing brand. Watch Horizon (or run `php artisan queue:work` in a separate terminal) — jobs complete without errors.
- [ ] **Step 9.3:** Verify in Cloudflare dashboard → DNS → Records: every brand has a CNAME `<brand>.partna.au → shops.myshopify.com` (DNS-only, grey cloud).
- [ ] **Step 9.4:** Sign up a new test affiliate via the dashboard with a primary brand selected. Verify:
  - `php artisan tinker` → `\App\Models\Core\Professional\BrandPartnerLink::latest()->first()->site_url` returns `https://<brand>.partna.au/<affiliate>`
  - `npx wrangler kv:key get --binding SUBDOMAIN_KV "<affiliate-handle>"` returns the JSON entry
  - Visiting `https://<affiliate-handle>.partna.au` 301s to the brand path
- [ ] **Step 9.5:** Sign up a new test brand. Verify:
  - DNS record appears in Cloudflare within ~30s
  - Visiting `https://<brand>.partna.au` reaches Shopify (will show Shopify's "shop unavailable" page until the brand connects their Hydrogen storefront — that's expected)
- [ ] **Step 9.6:** Final grep audit:
  ```
  grep -rn "custom_domain\|domain_mode\|storefront_base_url\|affiliate_page_url" app/ tests/
  ```
  Expected: zero hits in `app/`. Test fixtures may have stragglers if Step 4.8 missed any — clean those up.

**Acceptance:**
- All tests passing
- Backfill complete
- New brand and affiliate signups produce working URLs end-to-end
- Grep audit clean

---

## Out of scope for this plan

- **Frontend cleanup** (Partna-Frontend, Partna-Shopify-App) — separate workstream.
- **Hydrogen changes** — none required.
- **Email sender domain reverification** (`@partna.au` SPF/DKIM/DMARC) — operational, not in this plan.
- **`sidest.co` migration of existing brands** — pre-beta, no live customers per Josh, no-op.
- **Affiliate-without-primary-brand handling** — current product rule prevents this case; KV always has a target.

---

## Commit / PR

- Branch: `feat/cloudflare-routing-cleanup` from `development-v2`
- Recommended commits:
  1. Phase 1 Tasks 1-4 (legacy fields drop + custom_domain removal + observer cleanup) — backend code + migration
  2. Phase 1 Task 5 (auto-provision DNS) — jobs + observer wiring + backfill command + tests
  3. Phase 2 Task 6 (Worker tweaks) — single Worker file edit
- After backfill verifies clean, push and open PR to `development-v2`.
- Cloudflare manual setup (Task 7) and Worker deploy (Task 8) happen alongside the PR — don't merge until Worker is live, otherwise affiliate URLs return 404.

---

## Failure modes to watch

| Symptom | Likely cause |
|---|---|
| Brand signup succeeds but `<brand>.partna.au` doesn't resolve within 30s | `CloudflareDnsService` failing silently — check Nightwatch / queue logs for `ProvisionBrandDnsJob` errors. Verify `CLOUDFLARE_API_TOKEN` has DNS Edit perm on `partna.au` zone |
| Affiliate signup but `<affiliate>.partna.au` returns 404 | KV entry missing — check that `SyncSubdomainToKvJob` ran. Verify env vars `CLOUDFLARE_ACCOUNT_ID` and `CLOUDFLARE_KV_NAMESPACE_ID` are set on Laravel Cloud |
| Affiliate redirects but goes to wrong brand | Stale KV entry — check `BrandPartnerLink::site_url` in DB; if correct there but KV is wrong, dispatch `SyncSubdomainToKvJob` manually for that affiliate |
| Tests fail with "table column does not exist" after Task 4 migration | Test DB schema not refreshed. `php artisan migrate:fresh --env=testing` or restart the test runner |
| Worker deploys but route shows "inactive" | Wildcard A record not added or not proxied. Re-check Task 7.7 — must be orange cloud |
