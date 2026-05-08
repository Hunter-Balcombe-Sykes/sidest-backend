- [ ] **#NOOP-1** · P3 — `flushHeldCommissions` is an explicit no-op kept for backward compatibility only
    - **Where:** app/Services/Stripe/CommissionVoidService.php:334-345
    - **Affects:** Developers maintaining the codebase — the method exists solely so callers don't need conditional plumbing
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit `StripeConnectWebhookController` and any tests calling `flushHeldCommissions` and remove those calls
        - Delete the method once all callers are cleaned up
    - **Technical:** The method unconditionally returns `0` and logs that it's a "no-op in Phase 4+." The comment says it's kept "so the StripeConnectWebhookController and tests can still call it without conditional plumbing." This is dead code by the author's own admission — the Phase 3.5+ commerce.orders model creates orders as 'approved' immediately, so there is no held state to flush. The method exists only to prevent callers from breaking when they invoke a now-nonexistent method.
    - **Plain English:** Think of an old light switch on the wall that's been disconnected from the wiring. Flipping it does nothing, but it's still there because removing it would leave a hole in the drywall. This method is that switch — it exists only so other parts of the code don't crash when they try to use it. The fix is to patch the drywall (remove the callers) and take the switch off.
    - **Evidence:**
        ```php
        public function flushHeldCommissions(Professional $affiliate): int
        {
            Log::info('flushHeldCommissions is a no-op in Phase 4+ — orders are approved on creation', [
                'affiliate_id' => (string) $affiliate->id,
            ]);

            return 0;
        }
        ```

- [ ] **#NOOP-2** · P3 — `hydrateTypographySettings` returns input unchanged — empty method body
    - **Where:** app/Services/Cache/SiteCacheService.php:207-211
    - **Affects:** Site payload cache — the method is called on every cache fill but does nothing
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either implement the typography hydration logic or remove the method and its callsite in `safeHydrateSitePayload`
        - If keeping for future use, add a `@todo` comment explaining the planned implementation
    - **Technical:** `hydrateTypographySettings` accepts `$settings` and `$brandProfessionalId` but returns `$settings` without any transformation. It's called from `hydrateSiteWithBrandTypography`, which is called from `safeHydrateSitePayload` on every cache fill. The call chain is: `getPublicSitePayload` → `safeHydrateSitePayload` → `hydrateSiteWithBrandTypography` → `hydrateTypographySettings`. Three method calls deep to do nothing. If typography hydration was planned but never implemented, this is dead scaffolding.
    - **Plain English:** Imagine a conveyor belt in a factory with a station labeled "Quality Check" where a worker stands, looks at each item, and places it right back on the belt unchanged. The station exists, the worker shows up, but nothing actually happens. This method is that station — it's called with data, touches nothing, and passes it along. Either put the worker to actual use or remove the station.
    - **Evidence:**
        ```php
        public function hydrateTypographySettings(array $settings, string $brandProfessionalId): array
        {
            return $settings;
        }
        ```

- [ ] **#DUP-1** · P3 — `formatMoney()` duplicated across three services with identical logic
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:464-474, app/Services/Stripe/CommissionVoidService.php:520-530, app/Services/Notifications/CommerceNotificationService.php:176-186
    - **Affects:** Maintenance — any currency formatting change must be applied in three places
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the cents-to-currency-string logic into a shared helper (e.g., `App\Services\Formatting\MoneyFormatter` or a Blade directive)
        - Replace all three private implementations with calls to the shared helper
    - **Technical:** Three services define a near-identical private `formatMoney` (or `formatMoneyFromCents`) method: a match expression mapping currency codes to prefix symbols, then `number_format($cents / 100, 2)`. Two are in the same Stripe namespace and are byte-for-byte identical. The third in CommerceNotificationService differs only in fallback behaviour (defaulting to 'AUD'). This is textbook copy-paste duplication — a DRY violation that creates a maintenance hazard where updating the symbol map in one place leaves the others behind.
    - **Plain English:** Three different departments in the same company each built their own calculator to do the exact same math. When tax rates change, you have to update all three. The fix is to buy one shared calculator and put it in the break room — extract the formatting logic into one place everyone uses.
    - **Evidence:**
        ```php
        // CommissionPayoutService.php:464-474
        private function formatMoney(int $cents, string $currencyCode): string
        {
            $prefix = match (strtoupper($currencyCode)) {
                'USD' => '$',
                'GBP' => '£',
                'EUR' => '€',
                'AUD' => 'A$',
                default => strtoupper($currencyCode).' ',
            };
            return $prefix.number_format($cents / 100, 2, '.', ',');
        }

        // CommissionVoidService.php:520-530 — identical match block, same logic
        private function formatMoney(int $cents, string $currencyCode): string
        {
            $prefix = match (strtoupper($currencyCode)) {
                'USD' => '$',
                'GBP' => '£',
                'EUR' => '€',
                'AUD' => 'A$',
                default => strtoupper($currencyCode).' ',
            };
            return $prefix.number_format($cents / 100, 2, '.', ',');
        }
        ```

- [ ] **#LEGACY-1** · P3 — `createSetupIntent` is a legacy SetupIntent path kept for compatibility
    - **Where:** app/Services/Stripe/StripeConnectService.php:177-198
    - **Affects:** Brand payment method collection — the legacy path may still have active callers
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Search for all callers of `createSetupIntent` across controllers, tests, and frontend code
        - If none exist, remove the method and its accompanying docblock
        - If callers remain, migrate them to `createPaymentMethodSetupCheckoutSession` (the hosted Stripe Checkout replacement)
    - **Technical:** The docblock says "Legacy SetupIntent path (kept for compatibility)." The replacement is `createPaymentMethodSetupCheckoutSession`, which uses Stripe's hosted Checkout flow instead of the client-side Elements + SetupIntent pattern. Both methods ultimately call `createCustomer` and produce a way to collect a reusable payment method, but the legacy path requires the frontend to handle the SetupIntent client_secret. As long as this method exists, there's ambiguity about which path callers should use, and any Stripe API changes to SetupIntents must be tested against both paths.
    - **Plain English:** The company installed a new front door with a modern keypad lock, but left the old door with its rusty key lock still attached to the building. Anyone could still walk up and use the old door. This method is that old door — it still works, but the new one is better and safer. Audit who's still using the old door, move them to the new one, then seal it shut.
    - **Evidence:**
        ```php
        /**
         * Legacy SetupIntent path (kept for compatibility).
         */
        public function createSetupIntent(Professional $brand): array
        {
            $customerId = $brand->stripe_customer_id;
            if (! $customerId) {
                $customerId = $this->createCustomer($brand);
            }
            $setupIntent = $this->stripe->setupIntents->create([
                'customer' => $customerId,
                'payment_method_types' => ['card', 'au_becs_debit'],
                'metadata' => [
                    'sidest_professional_id' => $brand->id,
                ],
            ]);
            return [
                'client_secret' => $setupIntent->client_secret,
                'setup_intent_id' => $setupIntent->id,
            ];
        }
        ```
