# Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline

Audit the **test suite** for coverage of the code paths that the other five lenses identify as risky. A static-analysis audit finds *what could break*; a test-coverage audit finds *whether the safety net catches it*. The combination is what gives confidence at pilot.

This lens reads under `tests/`, `app/`, and `database/factories/`. It does not run the tests — that's CI's job. It looks for **missing tests, brittle tests, and tests that lie about coverage**.

## Use the lens prefix `TEST` for findings

Number them `TEST-1`, `TEST-2`, … sequentially.

## Partna testing conventions (from CLAUDE.md + memory)

- **Pest** is the framework. Feature tests under `tests/Feature/`, unit tests under `tests/Unit/`.
- **Auth helpers**: `actingAsBrand($pro)`, `actingAsAffiliate($pro)`, `actingAsProfessional($pro)` — from `tests/Pest.php` (`ff7dc18`).
- **Stripe webhook + mock helpers** also in `tests/Pest.php` for Stripe-touching tests.
- **Real-DB integration**: per memory `feedback_workflow_preferences.md` / `feedback_shopify_integration_lessons.md`, integration tests must hit a real Supabase Postgres, never mocks of the database layer. Mocking the DB has historically hidden migration / FK / trigger bugs.
- **Vendor mocks are fine** (Stripe, Shopify, Cloudflare, Hydrogen) — those are external services with stable SDKs.
- **Policies + Form Requests are tested**: per `tests/Feature/Audit/*`, the codebase already has audit tests that assert structural invariants (e.g. financial models have no SoftDeletes — `29b7eb1`). Same pattern should cover the patterns this lens hunts.

## Findings categories

### (1) Critical-path coverage — financial flows

Each of the following code paths handles money or payout state. They MUST have feature tests covering happy path + at least one failure path. Flag any without.

- `CommissionPayoutService::processPayoutBatch` — happy path, in-flight, cancelled-by-revalidation, transfer-failure, race.
- `CommissionVoidService` — void on expiry, void on refund, void on retry-loop.
- `RetryPendingFundsPayoutsJob` — terminal failure → wallet credit; transient → re-queue.
- `VoidExpiredPayoutsJob` — T-30 / T-7 / T-1 warning fires once; JSONB dedup respected.
- `ReconcileStuckTransferringPayoutsJob` — picks up missed `transfer.paid`; logs reconciliation.
- `StripeConnectService::creditWalletFromCheckoutSession` — idempotent on duplicate session ID.
- Refund flows during grace — shrinks vs cancels in-flight payout.
- `commission_paid_cents` analytics path — only counts `payout.status='completed'` (`c3df357`).

### (2) Webhook idempotency + signature tests

- Every webhook controller in `app/Http/Controllers/Api/Webhooks/` should have at least two tests: signature pass and signature fail. Flag any without.
- Re-delivery test: same `event_id` posted twice → second is a no-op. Flag any webhook without this.
- Malformed payload test — handler doesn't crash, returns 400 / 422 cleanly.
- Webhook-event-ID dedup tested at the job layer (`ProcessShopifyOrderWebhookJob`, GDPR jobs, etc.).

### (3) Policy ability coverage

- Every method on every `Policy` class should have at least one test asserting `allowed` and one asserting `denied` for the appropriate actor.
- Sweep `app/Policies/*.php` — for each `public function` (excluding `BasePolicy` inherited methods), confirm a corresponding `it()` test exists in `tests/Feature/Policies/` or `tests/Feature/<domain>/`.
- `authorizeForUser` calls in controllers without a paired policy test of the gate they invoke.
- 404-on-not-yours assertion: per CLAUDE.md, denied-because-not-yours must 404, not 403. Flag policy tests that assert 403 where 404 is the contract.

### (4) Mock-vs-integration discipline

- DB mocks (`Mockery::mock(Model::class)`, `$this->mock(...)` on an Eloquent class, `Eloquent::shouldReceive`) — flag every instance. Per the memory rule, DB layer must be real.
- Migration-dependent tests that don't run migrations (missing `RefreshDatabase` / `LazilyRefreshDatabase` trait).
- Tests that mock observers / trigger-maintained tables — defeats the trigger correctness check (`brand_affiliate_rollup` is trigger-maintained; tests must hit the real trigger).
- Vendor SDK call sites tested without mocking the vendor (real Stripe / Shopify calls in CI) — slow + flaky.

### (5) Race-condition / concurrency tests

- Financial flows that depend on `lockForUpdate` should have a test that asserts two concurrent paths produce a single correct outcome (Laravel's `DB::transaction` testing or a dispatched-twice-job test).
- Wallet credit idempotency — concurrent dispatch produces one row, not two.
- Webhook re-delivery during in-flight processing — second delivery returns early.
- Status transitions racing with reconcile job.

### (6) Failed-job + retry coverage

- Every job with a `failed()` handler should have a test that asserts `failed()` is reachable and produces the expected side-effect (failure notification, alert, retry counter increment).
- Jobs with `$tries > 1` should have a test asserting backoff is respected (or document why it's untested).
- Idempotency under retry — every job that mutates state should have a test that runs `handle()` twice and asserts identical end-state.

### (7) Migration tests

- Every migration introducing a CHECK constraint should have a test that asserts the constraint actually rejects invalid values.
- Migrations adding UNIQUE should have a test that asserts duplicate inserts fail.
- Migrations adding FK should have a test asserting orphan-creation fails.
- The audit test pattern (`29b7eb1` — financial models without SoftDeletes) is the canonical example — extend it for new schema invariants the audits identify.

### (8) Resource class + Form Request coverage

- Every `Resource` class should have a snapshot test asserting the keys returned (catches accidental PII leaks on refactor).
- Every `FormRequest` should have a test asserting at least one valid + one invalid payload.
- Form Requests behind feature-flagged routes need both flagged-on and flagged-off tests.

### (9) Seed determinism + factory hygiene

- `database/factories/*` that call `faker->randomElement` on values that need to be deterministic for tests (e.g. status enums must produce all states across the test suite, not just one).
- Factories that don't relate to the model's required FK (creating an order without a brand) — silently inserts nulls or fails on FK.
- Factories used as fixtures in tests where a specific state matters but the factory's default is used — flag.

## Per-finding requirements

For every finding:
- Cite the category number (1–9).
- Name the canonical fix: `add it('happy path', ...)` + `it('failure path', ...)`, `assert dedup on re-delivery`, `replace Mockery::mock(Model) with real factory()`, `add denyAsNotFound assertion`, `add concurrent-dispatch test`.
- Quote the file path of the production code that lacks coverage, AND (if it exists) the path of the closest existing test file that should host the new test.
- A finding can be P0 if it's a financial-flow path with no coverage at all (regression risk = irreversible).

## Out of scope — do NOT re-flag

- Tests for booking / Fresha / Square (dropped).
- Tests for the commerce schema rebuild — closed audit.
- Code that's intentionally untested because it's a thin wrapper (Resource class straight-through, model getters without logic) — only flag when there's branching logic.
- Coverage percentage targets — meaningless without context.

## Suggested per-domain scope groups

### Group A — Financial flow tests
```
--scope tests/Feature/Stripe
--scope tests/Feature/Commerce
--scope tests/Feature/Commission
--scope app/Services/Stripe
--scope app/Jobs/Stripe
--scope app/Policies/CommissionPolicy.php
--scope app/Policies/WalletMovementPolicy.php
```

### Group B — Webhook idempotency
```
--scope tests/Feature/Webhooks
--scope tests/Feature/Shopify
--scope app/Http/Controllers/Api/Webhooks
--scope app/Jobs/Shopify
--scope app/Jobs/Gdpr
```

### Group C — Policy + auth coverage
```
--scope tests/Feature/Policies
--scope tests/Feature/Auth
--scope app/Policies
--scope app/Http/Middleware/Auth
```

### Group D — Resource / Form Request structure
```
--scope tests/Feature
--scope app/Http/Resources
--scope app/Http/Requests
```

### Group E — Migration invariants
```
--scope tests/Feature/Audit
--scope tests/Feature/Migrations
--scope supabase/migrations
--scope database/factories
```

## Exhaustiveness directive

Walk every production file in scope and check for a corresponding test. Walk every test file and check it asserts what it claims. Emit a finding for every distinct quotable gap. **A coverage audit that under-reports gives false confidence — exactly the failure mode you're auditing against.**
