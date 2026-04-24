# Shopify GDPR Webhooks — Ops Runbook

## Pre-submission checklist

### 1. Privacy policy page (required by Shopify reviewer)

A publicly accessible privacy policy URL must be linked from the Shopify Partner dashboard app listing. Ensure the page covers:

- What customer data Side St stores (email, phone, name, order-derived records)
- How long it is retained (soft-delete 30-day window, then purged on GDPR request)
- How merchants and their customers can request data export or deletion
- Contact details for data requests

Add the privacy policy URL in the Shopify Partner Dashboard → App → App setup → URLs → Privacy policy URL.

### 2. GDPR webhook endpoints configured in Shopify

In the Shopify Partner Dashboard → App → App setup → GDPR webhooks, set:

| Webhook | URL |
|---------|-----|
| Customer data request | `https://api.sidest.io/api/webhooks/shopify/gdpr/customers-data-request` |
| Customer redact | `https://api.sidest.io/api/webhooks/shopify/gdpr/customers-redact` |
| Shop redact | `https://api.sidest.io/api/webhooks/shopify/gdpr/shop-redact` |

### 3. `gdpr` Horizon queue configured

Add to `config/horizon.php` supervisor environments:

```php
'gdpr' => [
    'connection' => 'redis_gdpr',
    'queue' => ['gdpr'],
    'balance' => 'simple',
    'processes' => 1,
    'tries' => 3,
    'timeout' => 660,
],
```

The `gdpr` queue uses a **dedicated `redis_gdpr` connection** (not the default `redis` connection). This is critical: the default `redis` connection has `retry_after=360`, which is less than `RedactShopJob::$timeout=600`. Without `redis_gdpr`, Redis would re-queue the job while it was still running, causing concurrent duplicate execution of a destructive GDPR operation. The `redis_gdpr` connection sets `retry_after=660` (600s job timeout + 60s safety margin).

Worker command: `php artisan queue:work redis_gdpr --queue=gdpr --timeout=660`

### 4. Env vars set in production

```
GDPR_QUEUE=gdpr
GDPR_REDACT_PLACEHOLDER_DOMAIN=gdpr.sidest.io
GDPR_EXPORT_RETENTION_DAYS=30
```

---

## Dev-store dry run (before App Store submission)

Use the Shopify Partner Dashboard to fire test webhooks against your development store.

### Step 1: Trigger customer data request

In Partner Dashboard → Your app → Test → Send test webhook → `customers/data_request`.

Expected: HTTP 202 response, `gdpr_requests` row created with `status=received`, then `status=completed`. Merchant receives email with JSON attachment.

### Step 2: Trigger customer redact

Send `customers/redact` webhook.

Expected: 202 response, customer row anonymised (email overwritten, `redacted_at` set), `email_subscriptions` and `enquiries` rows deleted, `booking_events` PII nulled.

### Step 3: Trigger shop redact

Send `shop/redact` webhook.

Expected: 202 response, `access_token`/`refresh_token` nulled immediately, `affiliate_product_selections` deleted, Shopify-sourced customers anonymised, integration row deleted.

### Step 4: Verify idempotency

Re-send each webhook. Expected: 202 response, no new `gdpr_requests` row (duplicate `payload_hash` rejected), no second job dispatched.

### Step 5: Verify invalid HMAC

Send a request with a tampered body or wrong `X-Shopify-Hmac-SHA256` header.
Expected: HTTP 401, no `gdpr_requests` row created.

---

## Monitoring

Check `core.gdpr_requests` for stuck jobs:

```sql
SELECT id, topic, shop_domain, status, received_at, error
FROM core.gdpr_requests
WHERE status IN ('received', 'processing')
  AND received_at < NOW() - INTERVAL '1 hour'
ORDER BY received_at DESC;
```

Check Nightwatch for `RedactShopJob`, `RedactCustomerJob`, `ExportCustomerDataJob` failures.
