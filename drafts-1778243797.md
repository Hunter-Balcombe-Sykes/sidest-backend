- [ ] **#BLOT-1** · P3 — Dead `$onboardingComplete` parameter in `BrandOnboardingReadinessService::syncBrandStatus`
    - **Where:** app/Services/Professional/BrandOnboardingReadinessService.php:62-73
    - **Affects:** Developers maintaining onboarding code; parameter suggests a design intent (skip recompute when already known) that was abandoned.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `bool $onboardingComplete` parameter from the method signature.
        - Update the single call site in `getChecklist` to call `$this->syncBrandStatus($professional)` without the unused argument.
    - **Technical:** The method accepts `$onboardingComplete` but immediately delegates to `BrandStatusService::sync()` which recomputes all readiness gates from scratch. The parameter is never read — not in a guard clause, not in a log message, nowhere. It's a vestige of a previous implementation where the service may have written its own status row before the delegation to `BrandStatusService` was introduced.
    - **Plain English:** Imagine a checklist function that asks you to hand it your answer, then ignores your answer and re-does the entire calculation itself. The extra parameter is clutter — it makes readers wonder whether there's a bug, when in reality the data was just never wired up.
    - **Evidence:**
        ```php
        private function syncBrandStatus(Professional $professional, bool $onboardingComplete): string
        {
            // Delegate to BrandStatusService — it evaluates the full lifecycle
            // (building → preview → live) instead of just the binary active/deactivated
            // that the readiness checklist previously set.
            $statusService = app(BrandStatusService::class);
            $newStatus = $statusService->sync($professional);

            return $newStatus ?? BrandProfile::where('professional_id', $professional->id)
                ->value('brand_status') ?? BrandStatus::Onboarding->value;
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#BLOT-2** · P3 — `BrandOnboardingReadinessService` duplicates three readiness queries already present in `BrandStatusService`
    - **Where:** app/Services/Professional/BrandOnboardingReadinessService.php:78-117 (checkSiteImages, checkShopifyConnected, checkStripeConnected) vs app/Services/Professional/BrandStatusService.php:258-280 (hasMinimumImages, hasShopifyConnected, hasStripeConnected)
    - **Affects:** Future maintainers — any change to a readiness gate must be made in two places. The `BrandStatusService` versions also have per-request caching that the `BrandOnboardingReadinessService` versions lack.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `checkSiteImages`, `checkShopifyConnected`, and `checkStripeConnected` with calls into `BrandStatusService`'s equivalent private methods (or extract them to a shared helper/trait if they need to stay private).
        - Remove the duplicated query builders from `BrandOnboardingReadinessService`.
        - Verify the `getChecklist` response shape (which adds `label`/`current`/`required` keys) is preserved by constructing checklist items from the boolean results.
    - **Technical:** The three private check methods in `BrandOnboardingReadinessService` run the exact same Eloquent `exists()`/`count()` queries against `ProfessionalIntegration`, `SiteMedia`, and `Professional::$stripe_connect_status` as `BrandStatusService`. `BrandStatusService` additionally wraps the Shopify integration check in an instance-level `$shopifyConnectedCache` array to avoid duplicate DB hits within a single request. `getChecklist` calls all three checks, then calls `BrandStatusService::sync()` which runs them again — so a single checklist request hits the `professional_integrations` table at least twice for the same row.
    - **Plain English:** Two different departments each built their own copy of the same three-question survey. When the questions change (e.g., "how many images are required?"), someone has to remember to update both copies. One department even added a faster lookup shortcut, but the other never got the memo. Consolidate into one source of truth.
    - **Evidence:**
        ```php
        // BrandOnboardingReadinessService — checkShopifyConnected
        $connected = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->whereNotNull('access_token')
            ->whereNotNull('external_account_id')
            ->exists();
        ```
        ```php
        // BrandStatusService — hasShopifyConnected (same query, but cached per-instance)
        return $this->shopifyConnectedCache[$professionalId] = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->whereNotNull('access_token')
            ->whereNotNull('external_account_id')
            ->exists();
        ```
    - `[DRAFT, confidence: 0.90]`
