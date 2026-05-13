# Stripe Accounts v2 + 3-step direct-charge chain + Partna Treasury Connect account — full implementation plan

## Context

Replace the post-PR-#38 brand-as-merchant direct-charge implementation with a clean **v2 Accounts + 3-step direct-charge chain** model. This solves the accounting intent (brand's card → brand's Stripe balance → affiliate's Stripe balance → Partna Treasury Connect account) AND clears every bug from the audit (refund_application_fee missing, order status derivation broken, payout_id not released, webhook URLs dead, error context destroyed, legacy stuck payouts, PI ID nulled, etc.). Dev test data is wiped — only one test brand and one test affiliate, no real money at stake.

### Architecture decision (user-confirmed)

For each commission event, money flows through three Stripe API calls in sequence:

1. **Direct charge on brand's Connect account** — brand's saved card debited the full gross commission. Settlement merchant on cardholder statement = brand. Stripe processing fee absorbed by Partna's platform balance (we set `fees_collector='application'` on the brand's v2 Account). Funds land in the brand's Connect balance.
2. **Cross-account transfer brand → affiliate** — full gross commission moves from brand's balance to affiliate's balance, pinned to the charge via `source_transaction`. Brand's balance ends at $0.
3. **Cross-account transfer affiliate → Partna Treasury** — the platform fee portion moves from affiliate's balance to a dedicated "Partna Treasury" Connect account that we own. Affiliate keeps the net (gross − platform fee).

End state per $6 commission with $1.20 platform fee:
- Brand card debited $6.00, statement reads "Side St"
- Brand's Stripe books: $6 in (charge), $6 out (transfer to affiliate), net $0
- Affiliate's Stripe books: $6 in (transfer from Side St), $1.20 out (transfer to Partna Platform Fees), net $4.80
- Partna Treasury balance: +$1.20
- Partna platform balance: −$0.30 (Stripe processing fee absorbed)
- **Brand's accounting docs never mention Partna as a counterparty.** The brand sees a self-charge then a transfer to the affiliate. Clean.

### Why this model

The user explicitly chose this over the destination-charge-with-on_behalf_of alternative because:
- Money must visibly flow through the brand's Stripe balance (their books show "money in → money out to affiliate" cleanly).
- Affiliate must see Partna as the fee taker on their dashboard (visible as a Transfer-out to "Partna Platform Fees" — a Connect account whose display name communicates the purpose).
- Partna absorbs the Stripe processing fee so it doesn't muddy the brand's or affiliate's books with a deduction line.

### Stripe docs verified

- **v2 Accounts GA for Connect users.** No early-access toggle. Source: https://docs.stripe.com/connect/accounts-v2
- **No enablement step required.** Source: https://docs.stripe.com/connect/accounts-v2/migrate-integration — "you can immediately use the Accounts v2 API with your existing v1 Accounts without making any changes to them."
- **Direct charge fundamentals:** "The payment appears in the connected account's balance, not in your platform's balance." Source: https://docs.stripe.com/connect/charges
- **`card_payments` capability required for direct charges.** v1 docs require it together with `transfers`; v2 merchant configuration handles this via `configuration.merchant.capabilities.card_payments`.
- **Platform absorbs Stripe processing fee** when `defaults.responsibilities.fees_collector='application'`. Verified at https://docs.stripe.com/connect/direct-charges-fee-payer-behavior — "Platform absorbs all payment processing fees... the brand does NOT see Stripe fees deducted."
- **Cross-account transfer capability:** `stripe_balance.stripe_transfers` capability "enables this Account to receive /v1/transfers into their Stripe Balance" — confirmed from the platform OR from another connected account. Source: https://docs.stripe.com/api/v2/core/accounts/create
- **`source_transaction` on transfers** ensures the transfer waits for the source charge to settle. Used on the brand→affiliate transfer (step 2). Source: https://docs.stripe.com/connect/separate-charges-and-transfers
- **No source_transaction chaining** — step 3 (affiliate→Treasury) cannot reference step 2 as its source. Must wait synchronously for step 2 to return success OR retry asynchronously via webhook. Built into the state machine.
- **v2 Account creation parameters** verified at https://docs.stripe.com/api/v2/core/accounts/create:
  - `identity.entity_type`: `company` | `government_entity` | `individual` | `non_profit`
  - `defaults.responsibilities.fees_collector`: `application` | `application_custom` | `application_express` | `stripe`
  - `defaults.responsibilities.losses_collector`: `application` | `stripe`
  - `dashboard`: `express` | `full` | `none`
- **Refunds** support `refund_application_fee: true` AND `reverse_transfer: true` together on a single `/v1/refunds` call; both work proportionally on partial refunds. Source: https://docs.stripe.com/api/refunds/create

### What we use the Treasury Connect account for

- One v2 Account we onboard ONCE with Partna's own business details (ABN, address, operating bank account for payouts).
- Configuration: `recipient` only (receives transfers, pays out to Partna's bank).
- Display name: "Partna Platform Fees" — this is what affiliates see on their dashboard when the affiliate→Treasury transfer is recorded.
- Stripe payout schedule on the Treasury → daily to Partna's actual operating bank account.

---

## Phase 0 — Manual setup steps (you do these BEFORE I touch code)

### 0.1 Wipe legacy test data in dev Supabase

Run in Supabase SQL Editor (project ref `glncumufgaqcmqhzwrxm`):

```sql
DELETE FROM commerce.commission_payout_items WHERE payout_id IN (
    SELECT id FROM commerce.commission_payouts
);
DELETE FROM commerce.commission_payouts;
UPDATE commerce.orders SET payout_id = NULL;

UPDATE core.professionals SET
    stripe_connect_account_id = NULL,
    stripe_connect_status = 'not_connected',
    stripe_payment_method_id = NULL,
    stripe_payment_method_brand = NULL,
    stripe_payment_method_last4 = NULL
WHERE id IN (
    '019d58b6-4231-7220-83fd-c32ed77ce256',   -- Side St (brand)
    '019d51c7-9252-71d4-9af1-c19642fe8ed6'    -- vintage-boutqiu (affiliate)
);
```

Tell me when done.

### 0.2 Create the Partna Treasury v2 Account (one-time setup)

The Treasury holds Partna's platform fee earnings before they're paid out to Partna's operating bank. Onboard it once via Stripe Dashboard's manual Connect account creation:

1. Stripe Dashboard → Connect → Accounts → **Create account**
2. **Account configuration**:
   - Type: Standard or Express (Standard recommended so we can fully control + see the dashboard)
   - Country: Australia
3. **Business details**:
   - Type: Company
   - Legal business name: **Partna** (or your registered Partna entity name)
   - ABN: [your real Partna ABN]
   - Business website: https://partna.au
   - Business email: ops@partna.au or wherever fee notifications should land
4. **Dashboard display name**: **`Partna Platform Fees`** — this is what affiliates see on their Stripe dashboard when the affiliate→Treasury transfer is recorded. Choose this carefully; the affiliate will read this label.
5. **Capabilities to request**:
   - `stripe_balance.stripe_transfers` (receive transfers from affiliates)
   - `stripe_balance.payouts` (pay out from Treasury to Partna's operating bank)
6. **Add bank account**: link Partna's operating bank account (where Treasury funds payout to).
7. **Payout schedule**: daily, no delay.
8. Complete the onboarding flow as Partna's representative. Verify the account becomes `active`.
9. Copy the resulting Account ID (`acct_...`). Save it for step 0.4.

### 0.3 Update Laravel Cloud env vars

Laravel Cloud → development → Environment Variables. **Add**:

```
STRIPE_PARTNA_TREASURY_ACCOUNT_ID=acct_xxxxxxxxxxxxx   # from step 0.2
STRIPE_PLATFORM_WEBHOOK_SECRET=                         # filled after step 0.4
STRIPE_CONNECT_WEBHOOK_SECRET=                          # already there, will be re-issued after step 0.4
```

**Delete**:
```
STRIPE_WEBHOOK_SECRET    # legacy single-secret variable, no longer used
```

Verify already set:
- `STRIPE_SECRET_KEY` (sk_test_...)
- `STRIPE_API_VERSION=2026-02-25.clover`

Don't deploy yet — finish step 0.4 first.

### 0.4 Create TWO Stripe webhook destinations (delete the broken old ones first)

In Stripe Dashboard → Developers → Webhooks → **delete**:
- "One Link Connected Accounts" (`we_1TEb4MAEAFPm2LjX5iWG49ie`)
- "One Link Platform Events" (`we_1TEk1MAEAFPm2LjXhiZ7CnbE`)

Then create two new destinations.

**Destination A: "Partna Dev — Platform Events"**
- Endpoint URL: `https://dev-api.partna.au/api/webhooks/stripe-platform`
- Events from: **Your account** (selected during destination creation flow)
- API version: `2026-02-25.clover`
- Subscribed events:
  - v2 thin events on platform's v2 Accounts (which represent our connected brands/affiliates/treasury):
    - `v2.core.account.updated`
    - `v2.core.account[identity].updated`
    - `v2.core.account[configuration.merchant].updated`
    - `v2.core.account[configuration.customer].updated`
    - `v2.core.account[configuration.recipient].updated`
    - `v2.core.account[requirements].updated`
    - `v2.core.account.closed`
  - v1 events that fire on the platform during the 3-step chain:
    - `payment_intent.succeeded` (commission charge succeeded on brand's Connect account — note: this also fires on Connected accounts destination; subscribe here for the platform observability copy)
    - `payment_intent.payment_failed`
    - `charge.refunded` (refunds we issue)
    - `charge.dispute.created`
    - `checkout.session.completed` (brand card setup completion)

**Destination B: "Partna Dev — Connected Accounts"**
- Endpoint URL: `https://dev-api.partna.au/api/webhooks/stripe-connect`
- Events from: **Connected accounts** (selected during destination creation flow)
- API version: `2026-02-25.clover`
- Subscribed events:
  - `account.updated` (v1 snapshot fired when v2 Account configs change)
  - `account.application.deauthorized`
  - `payment_intent.succeeded` (commission charge succeeded — fires on the brand's Connect account scope)
  - `payment_intent.payment_failed`
  - `transfer.created` (brand→affiliate, affiliate→Treasury)
  - `transfer.paid` (brand→affiliate, affiliate→Treasury)
  - `transfer.failed`
  - `transfer.reversed`
  - `payment_method.attached` (PM saved on brand's account via Checkout Setup)
  - `payment_method.detached`

After creating each, reveal the `whsec_...` signing secret:
- Destination A's secret → set as `STRIPE_PLATFORM_WEBHOOK_SECRET` in Laravel Cloud
- Destination B's secret → set as `STRIPE_CONNECT_WEBHOOK_SECRET` in Laravel Cloud

Save env vars. Trigger redeploy if Laravel Cloud doesn't auto-redeploy. Wait for status `active`.

### 0.5 Verify webhook reachability

Once deployed, click "Send test event" on each destination:
- Destination A → `account.updated` → expect 200 OK
- Destination B → `account.updated` → expect 200 OK

If both return 200, manual setup is done. Tell me; I start the code.

If non-2xx, paste me the response body — we diagnose before continuing.

---

## Phase 1 — Schema migrations (mostly already applied)

Migration `20260513400000_stripe_v2_account_columns.sql` was applied earlier (dropped legacy columns, added stripe_payment_method_* card display fields, removed 'disconnected' from status check). Final shape on `core.professionals` for the new model:

```
stripe_connect_account_id       TEXT       -- v2 Account ID (acct_...) for brand/affiliate
stripe_connect_status           TEXT       -- not_connected | onboarding | active | restricted
stripe_payment_method_id        TEXT       -- PaymentMethod ID attached to brand's v2 Account (NULL for affiliates)
stripe_payment_method_brand     TEXT       -- 'visa' / 'mastercard' for UI display
stripe_payment_method_last4     TEXT       -- '4242' for UI display
country_code                    TEXT       -- ISO 3166-1 alpha-2
```

No further migration needed — the Treasury account ID lives in env var, not DB.

---

## Phase 2 — Config (partially applied)

`config/services.php` was updated in earlier work. Final shape:

```php
'stripe' => [
    'secret_key' => env('STRIPE_SECRET_KEY'),
    'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
    'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
    'platform_webhook_secret' => env('STRIPE_PLATFORM_WEBHOOK_SECRET'),
    'partna_treasury_account_id' => env('STRIPE_PARTNA_TREASURY_ACCOUNT_ID'),
    'api_version' => env('STRIPE_API_VERSION', '2026-02-25.clover'),
],
```

Add the `partna_treasury_account_id` line to the existing config.

---

## Phase 3 — Rewrite `StripeConnectService`

**File:** `app/Services/Stripe/StripeConnectService.php` — full rewrite. New public methods:

### 3a. Brand v2 onboarding (merchant + customer configs)

```php
public function createBrandConnectAccount(Professional $brand): string
{
    $account = $this->stripe->v2->core->accounts->create([
        'contact_email' => $brand->primary_email,
        'display_name' => $brand->display_name,
        'identity' => [
            'country' => $this->mapCountryCode($brand->country_code),
            'entity_type' => 'company',
            'business_details' => [
                'registered_name' => $brand->display_name,
            ],
        ],
        'defaults' => [
            'currency' => $this->resolveShopCurrency($brand) ?? 'aud',
            'responsibilities' => [
                'fees_collector' => 'application',   // Partna absorbs Stripe processing fees
                'losses_collector' => 'application', // Partna liable for negative balances
            ],
            'profile' => [
                'business_url' => $brand->partna_url,
            ],
        ],
        'configuration' => [
            'merchant' => [
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                ],
                'mcc' => '8641',
            ],
            'customer' => [
                // Enables PaymentMethod attachment so brand's saved card lives here
            ],
        ],
        'dashboard' => 'express',
        'metadata' => [
            'sidest_professional_id' => $brand->id,
            'professional_type' => 'brand',
        ],
        'include' => ['identity', 'configuration.merchant', 'configuration.customer', 'requirements'],
    ]);

    $brand->update([
        'stripe_connect_account_id' => $account->id,
        'stripe_connect_status' => 'onboarding',
    ]);

    return $account->id;
}
```

### 3b. Affiliate v2 onboarding (recipient config)

```php
public function createAffiliateConnectAccount(Professional $affiliate): string
{
    $account = $this->stripe->v2->core->accounts->create([
        'contact_email' => $affiliate->primary_email,
        'display_name' => $affiliate->display_name,
        'identity' => [
            'country' => $this->mapCountryCode($affiliate->country_code),
            'entity_type' => 'individual',
            'individual' => [
                'given_name' => $affiliate->first_name,
                'surname' => $affiliate->last_name,
            ],
        ],
        'defaults' => [
            'currency' => 'aud',
            'responsibilities' => [
                'fees_collector' => 'application',
                'losses_collector' => 'application',
            ],
            'profile' => [
                'business_url' => $affiliate->partna_url,
            ],
        ],
        'configuration' => [
            'recipient' => [
                'capabilities' => [
                    'stripe_balance' => [
                        'stripe_transfers' => ['requested' => true],   // Receive from brand's account
                        'payouts' => ['requested' => true],            // Pay out to affiliate's bank
                    ],
                ],
            ],
        ],
        'dashboard' => 'express',
        'metadata' => [
            'sidest_professional_id' => $affiliate->id,
            'professional_type' => 'affiliate',
        ],
        'include' => ['identity', 'configuration.recipient', 'requirements'],
    ]);

    $affiliate->update([
        'stripe_connect_account_id' => $account->id,
        'stripe_connect_status' => 'onboarding',
    ]);

    return $account->id;
}
```

### 3c. Single dispatcher

```php
public function createConnectAccount(Professional $professional): string
{
    return $professional->isBrand()
        ? $this->createBrandConnectAccount($professional)
        : $this->createAffiliateConnectAccount($professional);
}
```

### 3d. AccountLink for onboarding (v2)

```php
public function createOnboardingLink(Professional $pro, string $returnUrl, string $refreshUrl): string
{
    $accountId = $pro->stripe_connect_account_id ?: $this->createConnectAccount($pro);

    $link = $this->stripe->v2->core->accountLinks->create([
        'account' => $accountId,
        'use_case' => [
            'type' => 'account_onboarding',
            'account_onboarding' => [
                'configurations' => $pro->isBrand()
                    ? ['merchant', 'customer']
                    : ['recipient'],
                'return_url' => $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'fresh=1',
                'refresh_url' => $refreshUrl,
            ],
        ],
    ]);

    return $link->url;
}
```

### 3e. Brand card setup — Checkout Session attaches PM to brand's v2 Account

```php
public function createBrandPaymentMethodSetupSession(Professional $brand, string $successUrl, string $cancelUrl): array
{
    if (! $brand->stripe_connect_account_id || $brand->stripe_connect_status !== 'active') {
        throw new \RuntimeException('Brand must finish Stripe onboarding before adding a card.');
    }

    $session = $this->stripe->checkout->sessions->create(
        [
            'mode' => 'setup',
            'customer' => $brand->stripe_connect_account_id,   // v2 Account acts as customer
            'payment_method_types' => ['card'],
            'success_url' => $successUrl.(str_contains($successUrl, '?') ? '&' : '?').'stripe_pm_session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'sidest_professional_id' => $brand->id,
                'purpose' => 'brand_commission_payment_method',
            ],
        ],
        [
            'stripe_account' => $brand->stripe_connect_account_id,   // Checkout session lives on brand's account
        ],
    );

    return ['url' => $session->url, 'id' => $session->id];
}
```

### 3f. Sync the saved PM after Checkout return

```php
public function syncBrandPaymentMethodFromCheckoutSession(Professional $brand, string $sessionId): array
{
    $session = $this->stripe->checkout->sessions->retrieve(
        $sessionId,
        ['expand' => ['setup_intent.payment_method']],
        ['stripe_account' => $brand->stripe_connect_account_id],
    );

    if ($session->mode !== 'setup') {
        throw new \RuntimeException('Checkout session is not a setup session.');
    }
    if ($session->status !== 'complete') {
        throw new \RuntimeException('Setup session is not complete yet.');
    }

    $setupIntent = $session->setup_intent;
    if (! $setupIntent || $setupIntent->status !== 'succeeded') {
        throw new \RuntimeException('Setup intent has not succeeded.');
    }

    $pm = $setupIntent->payment_method;
    if (! is_object($pm)) {
        throw new \RuntimeException('No payment method found on setup intent.');
    }

    $card = $pm->card ?? null;
    $brand->update([
        'stripe_payment_method_id' => $pm->id,
        'stripe_payment_method_brand' => $card?->brand,
        'stripe_payment_method_last4' => $card?->last4,
    ]);

    return [
        'payment_method_id' => $pm->id,
        'brand' => $card?->brand,
        'last4' => $card?->last4,
    ];
}
```

### 3g. Disconnect

```php
public function disconnectAccount(Professional $pro): void
{
    $pro->update([
        'stripe_connect_status' => 'not_connected',
        'stripe_connect_account_id' => null,
        'stripe_payment_method_id' => null,
        'stripe_payment_method_brand' => null,
        'stripe_payment_method_last4' => null,
    ]);
}
```

### 3h. Status sync (v2 capability inspection)

```php
public function syncAccountStatus(Professional $pro): array
{
    if (! $pro->stripe_connect_account_id) {
        return [
            'status' => 'not_connected',
            'charges_enabled' => false,
            'transfers_enabled' => false,
            'details_submitted' => false,
            'requirements' => [],
        ];
    }

    return $this->cacheLock->rememberLocked(
        self::statusCacheKey($pro->stripe_connect_account_id),
        self::STATUS_CACHE_TTL,
        fn () => $this->fetchAndSyncAccountStatus($pro, $pro->stripe_connect_account_id),
    );
}

private function fetchAndSyncAccountStatus(Professional $pro, string $accountId): array
{
    $account = $this->stripe->v2->core->accounts->retrieve($accountId, [
        'include' => ['identity', 'configuration.merchant', 'configuration.customer', 'configuration.recipient', 'requirements'],
    ]);

    $status = self::determineAccountStatus($account, $pro);

    if ($pro->stripe_connect_status !== $status) {
        $pro->update(['stripe_connect_status' => $status]);
    }

    return [
        'status' => $status,
        'charges_enabled' => $this->capabilityIsActive($account, 'merchant', 'card_payments'),
        'transfers_enabled' => $this->capabilityIsActive($account, 'recipient', 'stripe_balance.stripe_transfers'),
        'details_submitted' => ! empty($account->requirements?->summary?->minimum_deadline?->status),
        'requirements' => $this->summarizeRequirements($account),
    ];
}

public static function determineAccountStatus(object $account, Professional $pro): string
{
    if ($pro->isBrand()) {
        $cardPayments = $account->configuration?->merchant?->capabilities?->card_payments?->status ?? 'unsupported';
        return match ($cardPayments) {
            'active' => 'active',
            'restricted' => 'restricted',
            default => 'onboarding',
        };
    }

    $transfers = $account->configuration?->recipient?->capabilities?->stripe_balance?->stripe_transfers?->status ?? 'unsupported';
    return match ($transfers) {
        'active' => 'active',
        'restricted' => 'restricted',
        default => 'onboarding',
    };
}

private function capabilityIsActive(object $account, string $configKey, string $capabilityPath): bool
{
    $config = $account->configuration?->{$configKey} ?? null;
    if (! $config) {
        return false;
    }

    $parts = explode('.', $capabilityPath);
    $current = $config->capabilities ?? null;
    foreach ($parts as $part) {
        $current = $current?->{$part} ?? null;
        if (! $current) {
            return false;
        }
    }
    return ($current->status ?? null) === 'active';
}

private function summarizeRequirements(object $account): array
{
    $entries = $account->requirements?->entries ?? [];
    return array_values(array_filter(array_map(
        fn ($e) => $e->path ?? null,
        is_array($entries) ? $entries : [],
    )));
}
```

### 3i. Dashboard login link (v2)

```php
public function createDashboardLink(Professional $pro): ?string
{
    if (! $pro->stripe_connect_account_id || $pro->stripe_connect_status !== 'active') {
        return null;
    }

    $link = $this->stripe->v2->core->accountLinks->create([
        'account' => $pro->stripe_connect_account_id,
        'use_case' => [
            'type' => 'account_management',
            'account_management' => [],
        ],
    ]);

    return $link->url;
}
```

### 3j. Helpers — keep these from current implementation

- `mapCountryCode(?string $code): string` — verify country is in supported list, abort 422 if not
- `mapCountryCodeOrNull(?string $code): ?string` — tolerant variant for prefill
- `resolveShopCurrency(Professional $pro): ?string` — read from Shopify integration
- `stringOrNull` / `e164PhoneOrNull` — string helpers
- `STRIPE_CONNECT_SUPPORTED_COUNTRIES` constant
- `statusCacheKey($accountId): string` + `forgetStatusCache($accountId): void` — cache helpers
- `brandHasPaymentMethod(Professional $brand): bool` — reads `stripe_payment_method_id`

### 3k. Delete from current implementation

These methods served the old PR-#38 model and the v1 customer flow:
- `createBrandConnectCustomer` — no separate customer object in v2; brand's v2 Account IS the customer
- `createBrandConnectPaymentMethodSetupSession` → renamed to `createBrandPaymentMethodSetupSession`
- `syncBrandConnectPaymentMethodFromCheckoutSession` → renamed to `syncBrandPaymentMethodFromCheckoutSession`
- `saveBrandConnectPaymentMethod` — folded into the sync method
- `listPaymentMethods` — simplified or removed (just expose the stored brand fields)
- `removeBrandPaymentSetup` — replaced by `disconnectAccount` + a separate "remove card" endpoint that detaches the PM

---

## Phase 4 — Rewrite `CommissionPayoutService` for the 3-step chain

**File:** `app/Services/Stripe/CommissionPayoutService.php`

### 4a. Eligibility filter

```php
private function findEligibleBrands(): array
{
    return Professional::query()
        ->where('professional_type', 'brand')
        ->whereNotNull('stripe_connect_account_id')
        ->where('stripe_connect_status', 'active')
        ->whereNotNull('stripe_payment_method_id')
        ->pluck('id')
        ->all();
}

private function findEligibleAffiliateIds(): array
{
    return Professional::query()
        ->whereNotNull('stripe_connect_account_id')
        ->where('stripe_connect_status', 'active')
        ->pluck('id')
        ->all();
}
```

`processEligiblePayouts` joins eligible brands × eligible affiliates × orders with `status='approved' AND refund_cents=0 AND payout_id IS NULL AND rate_source<>'pending' AND occurred_at <= cutoff_by_hold_days`. Same shape as today; just the column requirements change.

Drop `'transferring'` from the resume filter (since we no longer have a "park at transferring" state — the chain either completes or fails atomically).

### 4b. The 3-step chain in `processPayoutBatch`

```php
public function processPayoutBatch(CommissionPayout $payout): ?bool
{
    $brand = Professional::find($payout->brand_professional_id);
    $affiliate = Professional::find($payout->affiliate_professional_id);
    $treasuryAccountId = config('services.stripe.partna_treasury_account_id');

    if (! $brand || ! $affiliate || ! $treasuryAccountId) {
        $this->failPayout($payout, 'config_error', 'Brand, affiliate, or Treasury config missing');
        return false;
    }

    // Re-validate eligibility (brand still active, has PM, etc.)
    if (! $this->stillEligible($brand, $affiliate)) {
        $this->failPayout($payout, 'no_longer_eligible', 'Brand or affiliate no longer eligible at execution time');
        return false;
    }

    $payout->forceFill(['status' => 'collecting'])->save();

    // === STEP 1: Direct charge on brand's Connect account ===
    try {
        $paymentIntent = $this->createBrandCharge($payout, $brand);
        $payout->forceFill([
            'stripe_payment_intent_id' => $paymentIntent->id,
            'charge_cents' => $payout->gross_commission_cents,
        ])->save();

        if ($paymentIntent->status !== 'succeeded') {
            // SCA or async — webhook completes it
            $payout->forceFill(['status' => 'collecting'])->save();
            return null;
        }

        $latestChargeId = $this->extractLatestChargeId($paymentIntent);
    } catch (ApiConnectionException|RateLimitException $e) {
        throw $e;  // Horizon retries — no money moved
    } catch (ApiErrorException $e) {
        $this->failPayout($payout, 'charge_failed', $this->formatStripeError($e));
        return false;
    }

    $payout->forceFill(['status' => 'transferring'])->save();

    // === STEP 2: Transfer brand → affiliate (full gross) ===
    try {
        $brandToAffiliate = $this->createBrandToAffiliateTransfer($payout, $brand, $affiliate, $latestChargeId);
        $payout->forceFill(['stripe_transfer_id' => $brandToAffiliate->id])->save();
    } catch (ApiConnectionException|RateLimitException $e) {
        throw $e;
    } catch (ApiErrorException $e) {
        $this->autoRefundChargeOnly($payout, $brand, 'brand_to_affiliate_failed', $e);
        return false;
    }

    // === STEP 3: Transfer affiliate → Partna Treasury (the fee) ===
    if ($payout->platform_fee_cents > 0) {
        try {
            $affiliateToTreasury = $this->createAffiliateToTreasuryTransfer($payout, $affiliate, $treasuryAccountId);
            $payout->forceFill(['stripe_fee_transfer_id' => $affiliateToTreasury->id])->save();
        } catch (ApiConnectionException|RateLimitException $e) {
            // Step 3 is retryable — leave state as 'transferring', let webhook/Horizon retry
            throw $e;
        } catch (ApiErrorException $e) {
            // Step 3 failed terminally: reverse step 2 + refund step 1
            $this->reverseBrandToAffiliateAndRefundCharge($payout, $brand, $affiliate, 'fee_transfer_failed', $e);
            return false;
        }
    }

    // All three steps succeeded
    $payout->forceFill([
        'status' => 'completed',
        'processed_at' => now(),
        'transfer_completed_at' => now(),
        'failure_code' => null,
        'failure_reason' => null,
    ])->save();

    $this->analyticsCache->bumpAnalyticsVersion($brand->id);
    $this->analyticsCache->bumpAnalyticsVersion($affiliate->id);

    Log::info('Commission payout completed (3-step chain)', [
        'payout_id' => $payout->id,
        'gross_cents' => $payout->gross_commission_cents,
        'platform_fee_cents' => $payout->platform_fee_cents,
        'net_to_affiliate_cents' => $payout->gross_commission_cents - $payout->platform_fee_cents,
    ]);

    return true;
}
```

### 4c. The three step helpers

```php
private function createBrandCharge(CommissionPayout $payout, Professional $brand): object
{
    return $this->stripe->paymentIntents->create([
        'amount' => $payout->gross_commission_cents,
        'currency' => strtolower($payout->currency_code),
        'customer' => $brand->stripe_connect_account_id,        // v2 Account = customer
        'payment_method' => $brand->stripe_payment_method_id,
        'confirm' => true,
        'off_session' => true,
        'description' => "Commission payout #{$payout->id}",
        'metadata' => [
            'sidest_payout_id' => $payout->id,
            'brand_id' => $brand->id,
            'step' => '1_brand_charge',
        ],
    ], [
        'stripe_account' => $brand->stripe_connect_account_id,  // Direct charge on brand
        'idempotency_key' => 'pi_'.$payout->id.($payout->retry_count > 0 ? '_r'.$payout->retry_count : ''),
    ]);
}

private function createBrandToAffiliateTransfer(
    CommissionPayout $payout,
    Professional $brand,
    Professional $affiliate,
    ?string $latestChargeId,
): object {
    $payload = [
        'amount' => $payout->gross_commission_cents,            // FULL gross
        'currency' => strtolower($payout->currency_code),
        'destination' => $affiliate->stripe_connect_account_id,
        'description' => "Commission to {$affiliate->display_name} for #{$payout->id}",
        'metadata' => [
            'sidest_payout_id' => $payout->id,
            'brand_id' => $brand->id,
            'affiliate_id' => $affiliate->id,
            'step' => '2_brand_to_affiliate',
        ],
    ];

    if ($latestChargeId) {
        $payload['source_transaction'] = $latestChargeId;  // Pin to settled charge
    }

    return $this->stripe->transfers->create($payload, [
        'stripe_account' => $brand->stripe_connect_account_id,  // Transfer originates from brand's balance
        'idempotency_key' => 'tr2_'.$payout->id,
    ]);
}

private function createAffiliateToTreasuryTransfer(
    CommissionPayout $payout,
    Professional $affiliate,
    string $treasuryAccountId,
): object {
    return $this->stripe->transfers->create([
        'amount' => $payout->platform_fee_cents,
        'currency' => strtolower($payout->currency_code),
        'destination' => $treasuryAccountId,
        'description' => "Partna platform fee for #{$payout->id}",
        'metadata' => [
            'sidest_payout_id' => $payout->id,
            'affiliate_id' => $affiliate->id,
            'step' => '3_affiliate_to_treasury',
        ],
    ], [
        'stripe_account' => $affiliate->stripe_connect_account_id,  // Transfer originates from affiliate's balance
        'idempotency_key' => 'tr3_'.$payout->id,
    ]);
}
```

### 4d. Failure compensations

```php
private function autoRefundChargeOnly(
    CommissionPayout $payout,
    Professional $brand,
    string $failureCode,
    ApiErrorException $e,
): void {
    // Step 2 failed — money is still in brand's balance. Refund the charge.
    try {
        $this->stripe->refunds->create([
            'payment_intent' => $payout->stripe_payment_intent_id,
            'metadata' => ['sidest_payout_id' => $payout->id, 'reason' => 'auto_refund_step2_failed'],
        ], [
            'stripe_account' => $brand->stripe_connect_account_id,
            'idempotency_key' => "rf_{$payout->id}_step2_failed",
        ]);
        $finalCode = $failureCode.'_refunded';
    } catch (\Throwable $refundEx) {
        $finalCode = $failureCode.'_refund_failed';
        $payout->forceFill(['needs_manual_refund' => true])->save();
        Log::error('Step 1 refund after step 2 failure failed', [
            'payout_id' => $payout->id,
            'transfer_error' => $this->formatStripeError($e),
            'refund_error' => $refundEx->getMessage(),
        ]);
    }
    $this->failPayout($payout, $finalCode, $this->formatStripeError($e));
}

private function reverseBrandToAffiliateAndRefundCharge(
    CommissionPayout $payout,
    Professional $brand,
    Professional $affiliate,
    string $failureCode,
    ApiErrorException $e,
): void {
    // Step 3 failed — money is in affiliate's balance via step 2. We need to:
    //   a) Reverse step 2 (affiliate → back to brand via transfer reversal)
    //   b) Refund step 1 (charge to brand's card)
    $finalCode = $failureCode;

    if ($payout->stripe_transfer_id) {
        try {
            $this->stripe->transfers->createReversal($payout->stripe_transfer_id, [
                'metadata' => ['sidest_payout_id' => $payout->id, 'reason' => 'step3_failed_reverse_step2'],
            ], [
                'stripe_account' => $brand->stripe_connect_account_id,
                'idempotency_key' => "rev2_{$payout->id}",
            ]);
        } catch (\Throwable $revEx) {
            $finalCode = $failureCode.'_reverse_failed';
            $payout->forceFill(['needs_manual_refund' => true])->save();
            Log::error('Step 2 reversal after step 3 failure failed', [
                'payout_id' => $payout->id,
                'reverse_error' => $revEx->getMessage(),
            ]);
        }
    }

    // Then refund the charge regardless of reversal outcome — brand should not be out of pocket.
    if ($payout->stripe_payment_intent_id) {
        try {
            $this->stripe->refunds->create([
                'payment_intent' => $payout->stripe_payment_intent_id,
                'metadata' => ['sidest_payout_id' => $payout->id, 'reason' => 'step3_failed_refund_charge'],
            ], [
                'stripe_account' => $brand->stripe_connect_account_id,
                'idempotency_key' => "rf_{$payout->id}_step3_failed",
            ]);
        } catch (\Throwable $refEx) {
            $finalCode .= '_refund_failed';
            $payout->forceFill(['needs_manual_refund' => true])->save();
        }
    }

    $this->failPayout($payout, $finalCode, $this->formatStripeError($e));
}

private function formatStripeError(\Throwable $e): string
{
    if ($e instanceof ApiErrorException) {
        return sprintf(
            '[%s] %s (request_id=%s, type=%s)',
            $e->getStripeCode() ?? 'unknown_code',
            $e->getMessage(),
            $e->getRequestId() ?? 'n/a',
            method_exists($e, 'getError') ? ($e->getError()?->type ?? 'n/a') : 'n/a',
        );
    }
    return get_class($e).': '.($e->getMessage() ?: 'unknown_error');
}

private function failPayout(CommissionPayout $payout, string $code, string $reason): void
{
    $payout->forceFill([
        'status' => 'failed',
        'failure_code' => $code,
        'failure_reason' => $reason,
        'processed_at' => now(),
    ])->save();

    // Release orders unless a manual refund is pending (in which case ops review first)
    $terminalCodes = ['fee_transfer_failed_refund_failed', 'fee_transfer_failed_reverse_failed', 'brand_to_affiliate_failed_refund_failed'];
    if (! in_array($code, $terminalCodes, true)) {
        Order::where('payout_id', $payout->id)->update(['payout_id' => null]);
        CommissionPayoutItem::where('payout_id', $payout->id)->delete();
    }

    Log::warning('Commission payout failed', [
        'payout_id' => $payout->id,
        'code' => $code,
        'reason' => $reason,
        'orders_released' => ! in_array($code, $terminalCodes, true),
    ]);
}
```

### 4e. Stale schema artifacts to keep

`commerce.commission_payouts` table needs a new column for the step-3 fee transfer ID. Add via new migration:

```sql
ALTER TABLE commerce.commission_payouts
    ADD COLUMN IF NOT EXISTS stripe_fee_transfer_id TEXT;

COMMENT ON COLUMN commerce.commission_payouts.stripe_fee_transfer_id IS
    'Transfer ID for step 3 of the chain (affiliate → Partna Treasury). NULL until step 3 succeeds.';
```

File: `supabase/migrations/20260513500000_add_fee_transfer_id_to_commission_payouts.sql`.

### 4f. Delete from `CommissionPayoutService`

- `extractBalanceTransactionNet` — no app_fee_amount on charge anymore, transfer amount is always full gross
- `markPendingFunding` (wallet leftover, gone)
- Any reference to `stripe_connect_customer_id` / `stripe_connect_payment_method_id`
- Two-step transfer logic that did app_fee on charge
- Wallet pre-debit / SCA cancel race code (already removed in earlier work, verify)

---

## Phase 5 — Rewrite `CommissionPayoutRefundService` (Shopify-refund clawbacks)

**File:** `app/Services/Stripe/CommissionPayoutRefundService.php`

When a Shopify order linked to a completed payout is refunded, we have to claw back proportionally across all 3 steps:

### 5a. Full payout clawback (all orders refunded)

```php
public function clawbackCompletedPayout(CommissionPayout $payout, string $reason): void
{
    $brand = Professional::find($payout->brand_professional_id);
    $affiliate = Professional::find($payout->affiliate_professional_id);

    // Reverse step 3: affiliate → Treasury transfer reversal
    if ($payout->stripe_fee_transfer_id) {
        $this->stripe->transfers->createReversal($payout->stripe_fee_transfer_id, [
            'metadata' => ['sidest_payout_id' => $payout->id, 'reason' => $reason],
        ], [
            'stripe_account' => $affiliate->stripe_connect_account_id,
            'idempotency_key' => "rev3_{$payout->id}_clawback",
        ]);
    }

    // Reverse step 2: brand → affiliate transfer reversal
    if ($payout->stripe_transfer_id) {
        $this->stripe->transfers->createReversal($payout->stripe_transfer_id, [
            'metadata' => ['sidest_payout_id' => $payout->id, 'reason' => $reason],
        ], [
            'stripe_account' => $brand->stripe_connect_account_id,
            'idempotency_key' => "rev2_{$payout->id}_clawback",
        ]);
    }

    // Refund step 1: charge refund on brand's account
    if ($payout->stripe_payment_intent_id) {
        $this->stripe->refunds->create([
            'payment_intent' => $payout->stripe_payment_intent_id,
            'metadata' => ['sidest_payout_id' => $payout->id, 'reason' => $reason],
        ], [
            'stripe_account' => $brand->stripe_connect_account_id,
            'idempotency_key' => "rf_{$payout->id}_clawback",
        ]);
    }

    $payout->forceFill([
        'status' => 'reversed',
        'failure_code' => 'clawback_full',
        'failure_reason' => $reason,
    ])->save();
}
```

### 5b. Partial payout clawback (one order in a multi-order payout refunded)

Math: if order's commission was X cents and payout's gross was G cents, refund ratio = X/G.

```php
public function clawbackPartialPayout(CommissionPayout $payout, Order $order, string $reason): void
{
    $clawbackGrossCents = $order->commission_cents;
    $proportionalFee = (int) round(
        $payout->platform_fee_cents * ($clawbackGrossCents / $payout->gross_commission_cents),
    );

    $brand = Professional::find($payout->brand_professional_id);
    $affiliate = Professional::find($payout->affiliate_professional_id);

    // Partial reverse of step 3 (affiliate → Treasury fee transfer): proportional amount
    if ($payout->stripe_fee_transfer_id && $proportionalFee > 0) {
        $this->stripe->transfers->createReversal($payout->stripe_fee_transfer_id, [
            'amount' => $proportionalFee,
            'metadata' => ['sidest_payout_id' => $payout->id, 'order_id' => $order->id, 'reason' => $reason],
        ], [
            'stripe_account' => $affiliate->stripe_connect_account_id,
            'idempotency_key' => "rev3_{$payout->id}_{$order->id}",
        ]);
    }

    // Partial reverse of step 2 (brand → affiliate transfer): proportional gross
    if ($payout->stripe_transfer_id) {
        $this->stripe->transfers->createReversal($payout->stripe_transfer_id, [
            'amount' => $clawbackGrossCents,
            'metadata' => ['sidest_payout_id' => $payout->id, 'order_id' => $order->id, 'reason' => $reason],
        ], [
            'stripe_account' => $brand->stripe_connect_account_id,
            'idempotency_key' => "rev2_{$payout->id}_{$order->id}",
        ]);
    }

    // Partial refund of step 1 charge: proportional gross
    if ($payout->stripe_payment_intent_id) {
        $this->stripe->refunds->create([
            'payment_intent' => $payout->stripe_payment_intent_id,
            'amount' => $clawbackGrossCents,
            'metadata' => ['sidest_payout_id' => $payout->id, 'order_id' => $order->id, 'reason' => $reason],
        ], [
            'stripe_account' => $brand->stripe_connect_account_id,
            'idempotency_key' => "rf_{$payout->id}_{$order->id}",
        ]);
    }

    // Update the order's row + the payout's running totals
    $order->forceFill(['refund_cents' => $order->refund_cents + $clawbackGrossCents])->save();
    $payout->forceFill([
        'reversed_commission_cents' => $payout->reversed_commission_cents + $clawbackGrossCents,
    ])->save();
}
```

### 5c. Delete from current `CommissionPayoutRefundService`

- Old single-step Stripe Transfer reversal code (we still use createReversal but now in a 3-step pattern, not the 1-step destination-charge pattern)
- `refund_application_fee: true` flag — no app_fee in our model, so don't pass it
- `reverse_transfer: true` flag — we explicitly reverse transfers ourselves in the right order

---

## Phase 6 — Split webhook controllers

### 6a. NEW: `app/Http/Controllers/Api/Webhooks/StripePlatformWebhookController.php`

```php
namespace App\Http\Controllers\Api\Webhooks;

use App\Models\Core\Professional\Professional;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;

class StripePlatformWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature', '');
        $secret = config('services.stripe.platform_webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        return $this->handleParsedEvent($event);
    }

    public function handleParsedEvent(\Stripe\Event $event): JsonResponse
    {
        match (true) {
            str_starts_with($event->type, 'v2.core.account.') => $this->handleV2AccountEvent($event),
            $event->type === 'payment_intent.succeeded' => $this->handlePlatformPISucceeded($event->data->object),
            $event->type === 'payment_intent.payment_failed' => $this->handlePlatformPIFailed($event->data->object),
            $event->type === 'charge.refunded' => $this->handleChargeRefunded($event->data->object),
            $event->type === 'charge.dispute.created' => $this->handleDisputeCreated($event->data->object),
            $event->type === 'checkout.session.completed' => $this->handleCheckoutSessionCompleted($event->data->object),
            default => Log::debug('Unhandled platform event', ['type' => $event->type]),
        };

        return response()->json(['received' => true]);
    }

    private function handleV2AccountEvent(\Stripe\Event $event): void
    {
        $accountId = $event->data->object->id ?? null;
        if (! $accountId) {
            return;
        }

        $pro = Professional::where('stripe_connect_account_id', $accountId)->first();
        if (! $pro) {
            Log::debug('v2.core.account.* event for unknown account', ['account_id' => $accountId, 'type' => $event->type]);
            return;
        }

        // Bust cache, then re-fetch + sync via the service
        StripeConnectService::forgetStatusCache($accountId);
        app(StripeConnectService::class)->syncAccountStatus($pro);
    }

    // ... handlePlatformPISucceeded (find payout by metadata.sidest_payout_id, mark step 1 complete)
    // ... handlePlatformPIFailed (autoRefundChargeOnly via service)
    // ... handleChargeRefunded (idempotency check)
    // ... handleCheckoutSessionCompleted (find brand, call syncBrandPaymentMethodFromCheckoutSession)
}
```

### 6b. Trim `StripeConnectWebhookController` to v1 events from connected accounts

Keep:
- `account.updated` (v1 snapshot from v2 Account config changes — sync status)
- `account.application.deauthorized`
- `payment_intent.succeeded` / `payment_intent.payment_failed` on connected accounts (step 1 of the chain — verify match by metadata.sidest_payout_id)
- `transfer.created` / `transfer.paid` / `transfer.failed` / `transfer.reversed` (step 2 + step 3 transfers)
- `payment_method.attached` / `payment_method.detached`

Remove handlers that referenced the old destination-charge / wallet code paths.

### 6c. Routes update — `routes/api.php`

```php
Route::post('/webhooks/stripe-connect', StripeConnectWebhookController::class);
Route::post('/webhooks/stripe-platform', StripePlatformWebhookController::class);
// Remove: Route::post('/webhooks/stripe', StripeWebhookController::class);   // legacy SaaS-billing controller — DELETE
```

Delete `StripeWebhookController.php` and any tests referencing it.

---

## Phase 7 — Order status derivation fix

**Files:**
- `app/Http/Controllers/Api/Professional/Brand/BrandOrdersController.php`
- `app/Http/Controllers/Api/Professional/Affiliate/AffiliateOrdersController.php`

Both controllers JOIN `commerce.commission_payouts cp ON cp.id = o.payout_id`. Add `cp.status as payout_status` to selects. Replace `deriveLifecycleStatus`:

```php
private function deriveLifecycleStatus(object $row): string
{
    if (in_array($row->order_status, ['cancelled', 'voided', 'refunded'], true)) {
        return 'reversed';
    }
    if ((int) $row->refund_cents >= (int) $row->net_cents && (int) $row->net_cents > 0) {
        return 'reversed';
    }
    if (empty($row->payout_id)) {
        return 'pending';
    }
    if (in_array($row->payout_status, ['failed', 'cancelled', 'reversed'], true)) {
        return 'pending';   // payout failed/reversed → order back in queue (defensive; Phase 4 also releases payout_id)
    }
    if ($row->payout_status === 'completed') {
        return 'paid';
    }
    return 'processing';   // collecting / transferring / pending
}
```

Update `applyStatusFilter` to use `cp.status='completed'` for the `paid` filter and exclude failed/reversed from `paid`.

---

## Phase 8 — `StripeConnectController` updates

**File:** `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php`

Endpoint changes:

### 8a. `GET /stripe/status`

```php
public function status(Request $request): JsonResponse
{
    $pro = $request->attributes->get('professional');

    if (! $pro->stripe_connect_account_id) {
        return response()->json([
            'connect' => ['status' => 'not_connected'],
            'has_payment_method' => false,
            'masked_card' => null,
        ]);
    }

    $accountStatus = $this->connectService->syncAccountStatus($pro);

    return response()->json([
        'connect' => $accountStatus,
        'has_payment_method' => $pro->isBrand() && ! empty($pro->stripe_payment_method_id),
        'masked_card' => $pro->isBrand() && $pro->stripe_payment_method_brand
            ? ['brand' => $pro->stripe_payment_method_brand, 'last4' => $pro->stripe_payment_method_last4]
            : null,
    ]);
}
```

### 8b. `POST /stripe/connect/onboard` — unchanged signature, calls renamed service method
### 8c. `POST /stripe/connect/dashboard` — unchanged, calls renamed service method
### 8d. `POST /stripe/connect/disconnect` — calls `disconnectAccount` which now also clears PM fields
### 8e. `POST /stripe/payment-method/setup-checkout` — calls `createBrandPaymentMethodSetupSession`
### 8f. `POST /stripe/payment-method/sync-checkout` — calls `syncBrandPaymentMethodFromCheckoutSession`
### 8g. `POST /stripe/payment-method/remove` — detach PM from brand's v2 Account + clear fields

---

## Phase 9 — Frontend overhaul

### 9a. Types — `Partna-Frontend/lib/stripe-connect.ts`

```ts
export type StripeConnectStatus = {
    connect: {
        status: 'not_connected' | 'onboarding' | 'active' | 'restricted'
        charges_enabled?: boolean
        transfers_enabled?: boolean
        details_submitted?: boolean
        requirements?: string[]
    }
    has_payment_method: boolean
    masked_card: { brand: string; last4: string } | null
}
```

Delete from this file (legacy):
- `StripeFundingMode` type
- `BrandTopupRecord` type
- Wallet fields on `BrandBillingSummary`
- `updateFundingMode`, `createTopupCheckoutSession`, `confirmTopupSession` functions
- Any `stripe_customer_id` / `stripe_connect_customer_id` references

### 9b. Stripe Connect Section UI — `features/integrations/components/stripe-connect-section.tsx`

Three flow branches:

**Brand branch:**
```
Step 1 — Connect Stripe (when status='not_connected'):
   Button: "Connect Stripe" → POST /stripe/connect/onboard → redirect to Stripe v2 onboarding (merchant + customer configs)

Step 2 — Add card (when status='active' && !has_payment_method):
   Button: "Add card" → POST /stripe/payment-method/setup-checkout → redirect to Checkout setup session

Step 3 — Active (when status='active' && has_payment_method):
   Card details: "Visa ending 4242"
   Button: "Remove card" → POST /stripe/payment-method/remove
   Status pill: "Active — commission payouts run automatically when orders are eligible"
   Link: "Open Stripe Dashboard" → POST /stripe/connect/dashboard

Restricted: Yellow warning + "Resolve Stripe issues" button → opens v2 dashboard link
Onboarding: "Continuing onboarding…" + Continue button → onboarding link
```

**Affiliate branch:**
```
Step 1 — Connect Stripe (when status='not_connected'):
   Button: "Connect Stripe" → POST /stripe/connect/onboard → redirect to v2 onboarding (recipient config only)

Active: "Connected — you'll receive commission payouts directly to your Stripe account"
   Link: "Open Stripe Dashboard"

Restricted: Yellow warning + dashboard link
```

### 9c. Payouts page — order status pill

Update `OrderHistoryTable` and `lib/payout-fixtures.ts` to handle 4 states: pending, processing, paid, reversed.

### 9d. Brand Billing Summary type — `Partna-Frontend/lib/`

Drop wallet fields. Already done in earlier PR; verify no leakage.

---

## Phase 10 — Tests

Test files to update / replace:

**Update / rewrite:**
- `tests/Feature/Stripe/BrandConnectOnboardingTest.php` — v2 Account creation payload assertions (merchant + customer configs)
- `tests/Feature/Stripe/BrandPaymentMethodSetupTest.php` — Checkout setup on brand's v2 Account
- `tests/Feature/Stripe/EligibilityFilterTest.php` — `stripe_payment_method_id` required (not customer_id / connect_customer_id)
- `tests/Feature/Stripe/CommissionPayoutServiceTest.php` — full rewrite for 3-step chain (mock all 3 Stripe calls, assert correct stripe_account header on each, source_transaction on step 2, idempotency keys)
- `tests/Feature/Stripe/StripeIdempotencyKeysTest.php` — new idempotency keys: `pi_<id>`, `tr2_<id>`, `tr3_<id>`, `rf_<id>_*`, `rev2_<id>_*`, `rev3_<id>_*`
- `tests/Feature/Stripe/PostPayoutClawbackTest.php` — clawback now does 3 reversals (step 3 → step 2 → step 1)
- `tests/Feature/Webhooks/Stripe/StripeConnectWebhookControllerEndToEndTest.php` — trim to v1 events from connected accounts

**New:**
- `tests/Feature/Stripe/PartnaTreasuryTransferTest.php` — step 3 happy path + step 3 failure compensation (reverse step 2 + refund step 1)
- `tests/Feature/Webhooks/Stripe/StripePlatformWebhookControllerTest.php` — v2 account events + platform PI events
- `tests/Feature/Commerce/OrderStatusDerivationTest.php` — status derivation across both Brand + Affiliate controllers; 4 states tested

**Delete:**
- `tests/Feature/Stripe/TransferToAffiliateTest.php` — replaced by full chain test
- `tests/Feature/Stripe/DirectChargePayoutTest.php` — replaced
- `tests/Feature/Stripe/ClawbackOnBrandAccountTest.php` — replaced by PostPayoutClawbackTest with 3-step coverage

---

## Phase 11 — Delete legacy code

Files to fully delete:
- `app/Http/Controllers/Api/Webhooks/StripeWebhookController.php` (legacy SaaS path)
- Any controller / service / job reference to:
  - `stripe_connect_customer_id`
  - `stripe_connect_payment_method_id`
  - `stripe_customer_id`
  - `stripe_manual_balance_cents` / `_currency` (wallet)
  - `stripe_grace_period_ends_at`
  - `stripe_payment_method_id` references that pointed at the old platform-side PM (now refers to brand's-Connect-side PM only)
  - `funding_mode` / `topup` anywhere
  - `application_fee_amount` on payment intents (we don't use app_fee in the new model)
  - `transfer_data.destination` on payment intents (we don't use destination charges)
  - `on_behalf_of` on payment intents (we use direct charge, not destination)

Env vars to remove:
- `STRIPE_WEBHOOK_SECRET` (replaced by `_PLATFORM_` and `_CONNECT_` variants)

Schema artifacts: the dropped columns are already gone via the Phase 1 migration applied earlier.

Tests for any of the above: delete or rewrite to match new model.

---

## Phase 12 — Pint + tests + commit + PR

```bash
cd /Users/tobiasbalcombeehrlich/Developer/Comet-Backend
vendor/bin/pint --dirty
php artisan test --compact tests/Feature/Stripe/ tests/Feature/Brand/ tests/Feature/Commerce/ tests/Feature/Webhooks/ tests/Feature/Professional/

cd /Users/tobiasbalcombeehrlich/Developer/Partna-Frontend
npm run typecheck && npm run lint
```

Iterate until clean. Single coordinated commit (or 3-4 logical commits) on `claude/stripe-v2-chain-overhaul`, PR into `development`, merge.

---

## Verification (end-to-end after merge)

1. **Endpoint reachability:**
   ```
   curl -X POST https://dev-api.partna.au/api/webhooks/stripe-platform   # → 400 (signature missing, endpoint up)
   curl -X POST https://dev-api.partna.au/api/webhooks/stripe-connect    # → 400
   ```

2. **Webhook delivery test:** Stripe Dashboard → both destinations → "Send test event" → expect 200 OK.

3. **Brand onboards (Side St):**
   - Settings → Payments → "Connect Stripe" → v2 onboarding (company, ABN, address, bank)
   - DB: `stripe_connect_account_id` populated, `stripe_connect_status='active'` (or 'onboarding')
   - Webhook: `v2.core.account[configuration.merchant].updated` lands at platform endpoint, 200 OK

4. **Brand adds card:**
   - "Add card" → Checkout setup → `4242 4242 4242 4242` → return
   - DB: `stripe_payment_method_id`, `stripe_payment_method_brand='visa'`, `stripe_payment_method_last4='4242'`
   - Webhook: `checkout.session.completed` lands at platform endpoint, 200 OK

5. **Affiliate onboards (vintage-boutqiu):**
   - Settings → Payments → "Connect Stripe" → v2 onboarding (individual, basic info, bank)
   - DB: `stripe_connect_status='active'`

6. **Place test Shopify order → instant payout fires:**
   - Watch backend logs for `ProcessShopifyOrderWebhookJob` → `ProcessCommissionPayoutsJob` → `ExecuteCommissionPayoutJob`
   - Watch logs: "Commission payout completed (3-step chain)"

7. **Stripe Dashboard verification:**
   - Platform's Payments view: no entries (we're not settlement merchant)
   - Brand's Connect Express dashboard: charge of $X + transfer of $X to vintage-boutqiu (net $0)
   - Affiliate's Connect Express dashboard: transfer received from Side St $X + transfer sent to Partna Platform Fees $Y (net $X-Y)
   - Partna Treasury's dashboard: transfer received from vintage-boutqiu $Y, balance +$Y

8. **DB row verification:**
   ```sql
   SELECT status, failure_code, stripe_payment_intent_id, stripe_transfer_id, stripe_fee_transfer_id
     FROM commerce.commission_payouts ORDER BY created_at DESC LIMIT 1;
   ```
   All three Stripe IDs populated, status='completed'.

9. **UI verification:**
   - Brand Payouts tab: order shows "Paid"
   - Affiliate Payouts tab: order shows "Paid"

10. **Negative path — trigger a step-1 failure:**
   - Swap brand's saved card to `4000000000000259` (fraudulent decline)
   - Place order → step 1 PI fails
   - DB: status='failed', failure_code='charge_failed', failure_reason has full Stripe error context
   - No transfer events fired (chain stopped at step 1)
   - Order released back to pending in both UIs

11. **Negative path — step-3 failure compensation:**
   - Briefly delete the Treasury account's bank link in Stripe Dashboard (simulates step 3 capability issue)
   - Place order → step 1 + step 2 succeed, step 3 fails
   - Service compensation runs: step 2 reversed (affiliate→brand), step 1 charge refunded
   - DB: status='failed', failure_code='fee_transfer_failed' or similar
   - Net effect: $0 in all accounts

12. **Shopify refund clawback:**
   - Refund the order in Shopify → `ProcessShopifyOrderRefundJob` → `clawbackPartialPayout` or `clawbackCompletedPayout`
   - Stripe Dashboard: all 3 reversals visible (step 3 reverse, step 2 reverse, step 1 refund)
   - DB: order.refund_cents incremented, payout.reversed_commission_cents updated
   - UI: order shows "Reversed"

---

## Files touched (estimate)

| Layer | Files | Lines (rough) |
|---|---|---|
| Backend migrations | 1 new (fee_transfer_id column) | 5 |
| Backend services | 3 rewrites (StripeConnectService, CommissionPayoutService, CommissionPayoutRefundService) | -1100 / +900 |
| Backend controllers | 1 new (StripePlatformWebhookController) + 1 trimmed (StripeConnectWebhookController) + 1 updated (StripeConnectController) + 2 updated (Brand/AffiliateOrdersController) | +500 / -200 |
| Routes + config | 2 files | +10 / -5 |
| Backend models | 1 (CommissionPayout: add stripe_fee_transfer_id to fillable + comment) | +5 |
| Tests | ~12 files updated, 3 new, 3 deleted | +800 / -500 |
| Frontend types | 1 (lib/stripe-connect.ts) | +30 / -60 |
| Frontend UI | 1 main (stripe-connect-section.tsx) + 1 payouts table | +200 / -150 |
| Frontend fixtures | 1 (payout-fixtures.ts) | +20 / -10 |

Total estimate: ~1800 lines net change.

---

## Risks + mitigations

| Risk | Mitigation |
|---|---|
| Cross-account Transfer (affiliate → Treasury) may not be a publicly-documented use case in current Stripe docs; the docs page focuses on platform→connected only | Capabilities doc explicitly confirms `stripe_balance.stripe_transfers` accepts transfers "from the platform or connected accounts". The pattern works via the `stripe_account` header on the Transfer create call. First implementation test catches any rejection. |
| Step 3 can't be source_transaction-pinned to step 2 — affiliate's balance might be in pending state for new accounts | State machine handles synchronously when balance is available; falls back to async retry via `transfer.paid` webhook on step 2 + scheduled retry job. Documented in Phase 4. For first-payout-from-new-affiliate, step 3 may complete asynchronously up to 2-7 days after step 1-2; this is acceptable for non-real-money dev testing. |
| v2 PHP SDK call shapes may differ from cURL docs | SDK v19.4.1 has full `$stripe->v2->core->{accounts,accountLinks}` services. Each method's first invocation surfaces any shape rejection clearly. Adjust + retest. |
| `customer = $brand->stripe_connect_account_id` on PaymentIntent.create may not accept a v2 Account ID directly | Per Stripe v2 docs, v2 Accounts can be referenced as v1 Customers via the same ID. If rejected, fallback: also create a v1 Customer on the brand's account during onboarding, store its ID, and use that as the PaymentIntent customer. |
| Treasury account onboarding requires real Partna business details (ABN, bank) | One-time manual step in Phase 0.2. User onboards as Partna's authorized representative. |
| Stripe processing fee absorbed by platform (~$0.30 per payout) reduces Partna's net margin from $1.20 → ~$0.90 | Documented and accepted per user choice. |
| Treasury's payout schedule may delay actual settlement of fees to Partna's operating bank | Default daily payout from Treasury → bank. Configurable. Document for ops. |
| Step 3 reversal during clawback requires affiliate → Treasury direction (clawback should reverse the fee back to affiliate so they're whole again? Or to brand?) | Step 3 reversal moves $1.20 from Treasury back to affiliate. Then step 2 reversal moves $X from affiliate back to brand. Then step 1 refund moves $X from brand to cardholder. Net: all parties whole, Treasury -$1.20. |
| Webhook event ordering not guaranteed — step 3's `transfer.created` might land before step 2's `transfer.paid` | Each webhook handler is idempotent — checks current DB state + acts only on transitions it understands. No assumed ordering. |
| Stripe Connect "Same region" requirement may block AU→AU→AU transfers if not configured correctly | All three accounts (brand, affiliate, Treasury) are AU. Same region. No cross-border. Confirmed safe. |
| Connect account "settlement currency" mismatch (e.g. brand in AU charging in USD) | Eligibility filter enforces matching `currency_code` on the order vs the brand's default currency. For multi-currency, separate plan. |

## Rollback

This is a single-PR all-or-nothing migration. Rollback path:
- `git revert` the merge commit
- Re-apply equivalent reverse-migration SQL if any new columns added (Phase 4e: drop `stripe_fee_transfer_id`)
- The Treasury Connect account stays on Stripe (it's an account; doesn't get destroyed by code rollback). Just leave it; we'll use it when we re-implement.

Lower-risk fallback if v2 SDK calls fail during implementation:
- Pause the PR
- Reimplement with v1 Express accounts (current architecture pre-this-PR), keeping the 3-step chain logic
- v1 Express accounts support direct charges + cross-account transfers + saved cards (the building blocks we need)
- We lose v2's "single Account = customer + merchant" elegance but the user-visible behavior is identical

---

## Out of scope (deferred to future PRs)

- Production Stripe Dashboard configuration (this plan covers dev only)
- Migrating prod brands/affiliates (no prod commerce yet)
- Multi-currency support (assume AUD throughout)
- Affiliate cash-out flow (Stripe Express handles)
- Custom-domain branding on Stripe Express
- Cross-border payouts
- SaaS subscription billing on brand accounts (would use the existing `customer` configuration we already enabled)
- Tax calculation / invoicing (Stripe Tax / Stripe Invoices) on the Partna platform fee
