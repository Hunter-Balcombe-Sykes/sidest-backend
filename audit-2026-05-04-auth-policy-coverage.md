# Auth Policy Coverage Audit — 2026-05-04

**Branch:** `development-v2`
**Lens:** auth/policy coverage on the new `SitePolicy` and the controllers it covers
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by Claude Opus 4.7 (this session)
**Source files audited:**
- `app/Policies/SitePolicy.php` (new)
- `tests/Unit/Policies/SitePolicyTest.php` (new)
- `app/Providers/AppServiceProvider.php` (modified)
- `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalGalleryController.php` (modified)
- `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php` (modified)

## Progress

- P1 High: 2 of 2 complete
- P2 Medium: 2 of 2 complete
- P3 Low: 1 of 1 complete

**Status:** All findings resolved 2026-05-05. Phase 5 closed: PolicyCoverageTest sweep enabled, CLAUDE.md updated, audit finding #1-01 marked done.

---

## P1 — Fix before pilot launch

- [x] **#AUTH-1** · P1 — `ProfessionalUploadController::upload` skips SitePolicy `create` — pending-deletion accounts can upload media
    - **Where:** app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:52-232 (`upload` method, no `authorizeForUser` call anywhere)
    - **Affects:** All media upload paths (gallery + content pools, image + video). Pending-deletion professionals retain write access to media even though `SitePolicy::create` was designed to gate exactly this.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Before the `DB::transaction` block, build a skeleton: `$skeleton = (new SiteMedia(['site_id' => $site->id]))->setRelation('site', $site);`
        - Call `$this->authorizeForUser($pro, 'create', $skeleton);`
        - Add a Pest test: pending-deletion professional → 423 on `POST /api/uploads`.
    - **Technical:** `SitePolicy::create` runs `denyIfPendingDeletion($actor)` before owner-match, returning a 423 Response. The controller resolves the site via `currentSite()` (which scopes by professional relationship), so SQL-level ownership is enforced — but the pending-deletion gate is the policy's *only* additional guarantee, and it never fires. This is the same gap pattern as #1-02 in `pilot-stage-1.md`, applied to the upload create path.
    - **Plain English:** When an account is scheduled for deletion, the policy says "no more changes." That rule lives inside the policy file. The upload endpoint never asks the policy, so a deleting account can keep adding photos and videos right up until their account is purged. The fix is one line — call the policy before creating the media row.
    - **Evidence:**
        ```php
        // ProfessionalUploadController::upload — full method, no authorizeForUser
        public function upload(UploadImageRequest $request): JsonResponse
        {
            $pro = $this->currentProfessional($request);
            $pro->loadMissing('site');
            $site = $this->currentSite($pro);
            // ... pool counting, video probe ...
            $media = DB::transaction(function () use ($site, $pool, $maxItems, ...) {
                // ... advisory lock ...
                $media = SiteMedia::create([
                    'site_id' => $site->id,
                    'pool' => $pool,
                    // ...
                ]);
                return $media;
            });
            // No $this->authorizeForUser($pro, 'create', $skeleton) anywhere.
        }
        ```

- [x] **#AUTH-2** · P1 — Brand-design create/delete paths skip SitePolicy entirely
    - **Where:** app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:440-612 — `uploadBrandLogo`, `destroyBrandLogo`, `uploadBrandPlaceholderImage`, `destroyBrandPlaceholder`, plus the shared `storeBrandDesignImage` helper
    - **Affects:** Brand logos, brand placeholder images. Pending-deletion brand accounts retain write access to brand design assets.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `storeBrandDesignImage`, before calling `BrandDesignMediaService`, authorize a SiteMedia skeleton: `(new SiteMedia(['site_id' => $site->id]))->setRelation('site', $site)` then `authorizeForUser($pro, 'create', ...)`.
        - In `destroyBrandLogo` and `destroyBrandPlaceholder`, fetch the existing SiteMedia row, attach the site relation, then `authorizeForUser($pro, 'delete', $media)` before delegating to the service.
        - Pest tests: pending-deletion brand → 423 on each endpoint.
    - **Technical:** Same pattern as #AUTH-1 but on the brand-design surface. These methods short-circuit through `BrandDesignMediaService` and never touch the SitePolicy gate, so neither pending-deletion nor (for delete) the spoofing-defense `site_id` re-check fires. The brand-only restriction is a separate concern (see #AUTH-3).
    - **Plain English:** Same problem as the regular upload endpoint, but on the brand-only buttons (logo upload, placeholder upload, and their delete counterparts). A brand whose account is being closed can still change the logo and decorative images. One missing policy call across each pair of methods.
    - **Evidence:**
        ```php
        // storeBrandDesignImage — no authorizeForUser before the brandDesign service call
        $media = match ($label) {
            'logo_full' => $this->brandDesign->upsertLogoFromUploadedFile($site, $pro->id, $file, 'full'),
            'logo_square' => $this->brandDesign->upsertLogoFromUploadedFile($site, $pro->id, $file, 'square'),
            'placeholder' => $this->brandDesign->addPlaceholder($site, $pro->id, $file),
        };

        // destroyBrandLogo
        $this->brandDesign->deleteLogo($site, $variant);  // no authorizeForUser

        // destroyBrandPlaceholder
        $this->brandDesign->deletePlaceholder($site, $media);  // no authorizeForUser
        ```

---

## P2 — Should fix

- [x] **#AUTH-3** · P2 — Brand-only routes use inline `professional_type` checks instead of the existing `brand.only` middleware
    - **Where:** app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php — six occurrences in `uploadBrandLogo` (446-448), `destroyBrandLogo` (467-469), `uploadBrandPlaceholderImage` (489-491), `listBrandPlaceholders` (515-517), `destroyBrandPlaceholder` (536-538), `reorderBrandPlaceholders` (558-560); routes at routes/api/professional.php:214-220
    - **Affects:** Brand-design route surface. The check works today, but the inline pattern violates the doctrine in `CLAUDE.md` and creates six places to keep in sync if the check evolves.
    - **Effort:** S (~1h)
    - **What to do:**
        - Wrap the brand-design routes in a middleware group: `Route::middleware('brand.only')->group(function () { ... brand-logo + brand-placeholder routes ... });`
        - Delete all six inline `if (($pro->professional_type ?? null) !== 'brand')` blocks from `ProfessionalUploadController`.
        - Add a Pest test: non-brand professional → 403 from the middleware (not the controller).
        - The `brand.only` middleware was added in `bcc758f` specifically for this — it's currently registered but unused on these routes.
    - **Technical:** `CLAUDE.md` mandates that authorization not live inline in controllers. The brand-design surface predates the middleware and was never migrated. Six near-identical guard blocks → one route group + zero controller code. CI's `INLINE_403` regex doesn't currently match `professional_type` checks, so these slipped through; tightening that regex would prevent recurrence.
    - **Plain English:** You already built the right tool for this — a middleware that says "only brand accounts past this point." It's just not being used here. The controller is doing the same check six times by hand. Apply the middleware to these six routes, delete the six handwritten checks. Same behavior, half the code, future-proof.
    - **Evidence:**
        ```php
        // routes/api/professional.php:214-220 — no brand.only middleware
        Route::post('/uploads/brand-logo', [ProfessionalUploadController::class, 'uploadBrandLogo']);
        Route::delete('/uploads/brand-logo', [ProfessionalUploadController::class, 'destroyBrandLogo']);
        Route::post('/uploads/brand-placeholder-image', [ProfessionalUploadController::class, 'uploadBrandPlaceholderImage']);
        // ...

        // ProfessionalUploadController::uploadBrandLogo — sample inline check
        if (($pro->professional_type ?? null) !== 'brand') {
            return $this->error('Brand logo uploads are only available for brand accounts.', 403);
        }
        ```

- [x] **#AUTH-4** · P2 — Reorder endpoints mutate `sort_order` without invoking SitePolicy `update`
    - **Where:** app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:304-385 (`reorder`); app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalGalleryController.php:73-112 (`reorder`)
    - **Affects:** Pending-deletion accounts can still reorder media. Lower blast radius than AUTH-1/AUTH-2 because reorder is reversible and doesn't create new state.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Fetch the first SiteMedia row in scope, attach the site relation, and call `authorizeForUser($pro, 'update', $media)` once before the transaction.
        - The per-row id-validation already inside the transaction is sufficient for ownership; the policy call adds the pending-deletion gate.
        - Pest test: pending-deletion → 423 on both reorder endpoints.
    - **Technical:** Same SitePolicy contract gap as AUTH-1/AUTH-2. Tier demoted to P2 because reorder is non-destructive (no new rows, no media deleted, easily corrected) and there's no privacy/security implication beyond the pending-deletion policy contract being violated.
    - **Plain English:** Same gap as the upload one, on the photo-shuffling endpoint. Lower stakes — nothing's lost or created, just rearranged. Still worth fixing for consistency.
    - **Evidence:**
        ```php
        // ProfessionalUploadController::reorder — no authorizeForUser
        DB::transaction(function () use ($site, $pool, $mediaType, $ids) {
            // advisory lock + id validation + reorder
            foreach ($finalIds as $index => $id) {
                SiteMedia::query()->where('site_id', $site->id)->where('id', $id)
                    ->update(['sort_order' => $index]);
            }
        });

        // ProfessionalGalleryController::reorder — same pattern
        DB::transaction(function () use ($site, $ids) {
            foreach ($newOrder as $i => $id) {
                SiteMedia::query()->where('site_id', $site->id)->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });
        ```

---

## P3 — Nice to have

- [x] **#AUTH-5** · P3 — `SitePolicyTest` missing coverage for 3 of 7 registered models and the spoofing-defense path
    - **Where:** tests/Unit/Policies/SitePolicyTest.php (covers Site, SiteMedia, Block; missing SiteSubdomainAlias, Enquiry, LeadSubmission, and the `setRelation` spoofing test)
    - **Affects:** Test confidence. The policy ships with a known-correct ownership-resolution algorithm and a non-obvious spoofing defense (the resource's `site_id` must match the preloaded site's `id`) — neither is exercised for half the models registered against it.
    - **Effort:** S (~1h)
    - **What to do:**
        - Add `describe` blocks for `SiteSubdomainAlias` (uses the same site-relation pathway as SiteMedia) and `Enquiry` + `LeadSubmission` (denormalized `professional_id` like Block).
        - Add a spoofing test: `$resource->site_id = 'site-real'`, `setRelation('site', $someoneElsesSite)` where `$someoneElsesSite->id = 'site-attacker'` — expect deny because the site_id mismatch fires.
        - One assertion per scenario; mirrors the Block tests.
    - **Technical:** `AppServiceProvider::boot()` registers SitePolicy for 7 models; the test covers 3. The spoofing defense at `SitePolicy.php:79-82` is the policy's most subtle invariant — it's the difference between "ownership through preloaded relation" and "anyone with `setRelation` access can spoof." A regression there would be silent.
    - **Plain English:** The new policy file has a clever defense against a specific attack: someone passing in a site that isn't really linked to the resource. We test the policy works for three of the seven things it protects, but not the other four — and not the clever defense itself. Add a few short tests so a future change can't quietly break it.
    - **Evidence:**
        ```php
        // SitePolicyTest.php — describe blocks present:
        describe('Site', ...);
        describe('SiteMedia', ...);
        describe('Block', ...);
        // Missing: SiteSubdomainAlias, Enquiry, LeadSubmission, and a spoofing test.

        // The spoofing defense being undefended in tests:
        // SitePolicy.php:79-82
        $resourceSiteId = $resource->getAttributes()['site_id'] ?? null;
        if ($resourceSiteId === null || (string) $resourceSiteId !== (string) $site->id) {
            return null;
        }
        ```
