---
item_id: '#WEB-4'
title: '`themes/publish` webhook returns 200 on invalid HMAC'
source: pilot-stage-3.md
tier: P2
effort_estimate: S
completed_at: '2026-05-08T02:13:42+00:00'
mode: overnight
commit_sha: cb56715
files_touched:
- app/Http/Controllers/Api/Webhooks/ShopifyThemePublishedWebhookController.php
test_result: pass
questions_asked: 0
---

# #WEB-4 — `themes/publish` webhook returns 200 on invalid HMAC

## Plain English

When someone sends a fake request to our Shopify theme-published webhook, we were responding with "thanks, got it!" instead of "you're not authorised." This meant that if our security keys ever went out of sync with Shopify, every delivery would still look successful — nothing would alert us. We've fixed it to return a proper 401 Unauthorised, which lets Shopify's own retry and alert system surface the misconfiguration.

## Technical Summary

**File changed:** `app/Http/Controllers/Api/Webhooks/ShopifyThemePublishedWebhookController.php`

In `__invoke`, the HMAC failure branch previously returned `$this->success(['received' => true])` with a suppression comment. It now returns `$this->error('invalid signature', 401)`, matching the identical pattern in `ShopifyOrdersUpdatedWebhookController`. The suppression comment was removed. No other behaviour changed.

## Decisions Made

- **Kept the Log::warning call:** The audit item said to remove the suppression comment, not the log line. The warning is useful breadcrumb data and consistent with all other webhook controllers.

## Notes

No existing tests cover this controller. The change is a 3-line diff (2 deletions, 1 insertion). All 1606 existing tests continue to pass.

## Questions Asked
(none)
