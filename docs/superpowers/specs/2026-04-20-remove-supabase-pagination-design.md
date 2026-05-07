# Design: Remove Supabase Pagination from OAuth Callback

**Date:** 2026-04-20
**Status:** Approved

## Problem

`SupabaseAdminService::getUserByEmail` paginates through every user in the GoTrue admin API to find a match by email. GoTrue has no email-filter endpoint, so this is O(n) in the number of Supabase users — getting slower as the user base grows. It is called on the Shopify OAuth callback request path, blocking the worker thread for the full duration.

Two call sites:
1. `ShopifyAppOAuthController` line 138 — Path B (existing account detection)
2. Inside `SupabaseAdminService::createUser` lines 71–80 — fallback when GoTrue returns 422/409 without a user ID in the body

## Solution

Replace both call sites so `getUserByEmail` is never called, then delete it.

### Change 1 — OAuth callback

Replace the `getUserByEmail` call with a local DB lookup on `professionals.primary_email`. This column already exists, is non-null, and has a dedicated lowercase search index (`professionals_email_search_idx`).

- If `primary_email` matches → existing account found, proceed to `handleExistingBrandConnect` (Path B unchanged)
- If no match → fall through to Path C (setup wizard) as today

Users whose Shopify store email differs from their Partna `primary_email` will land in the setup wizard. They log in with their existing credentials and the wizard links the Shopify store to their account — no duplicate is created.

### Change 2 — `createUser` fallback

The pagination fallback (lines 71–80) is labelled "legacy GoTrue versions". Supabase runs GoTrue v2, which always includes the existing user object in the 422/409 response body. The fallback is dead code in practice.

Remove it. If a 422/409 arrives without a user ID in the body, throw a `RuntimeException` immediately rather than scanning all users.

### Change 3 — Delete `getUserByEmail`

With no remaining call sites, delete the method entirely.

## Files Changed

| File | Change |
|------|--------|
| `app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php` | Replace `getUserByEmail` call with local `professionals.primary_email` query |
| `app/Services/Auth/SupabaseAdminService.php` | Remove `getUserByEmail` method; remove pagination fallback from `createUser` |

## What Does Not Change

- Path A (reinstall by shop domain) — untouched
- Path C (fresh install → setup wizard) — untouched
- `createUser` single POST to Supabase — retained, it is O(1) and only called during setup wizard
- `BootstrapController` — untouched
- All other auth flows

## Result

Zero Supabase API calls on the OAuth callback path. The only remaining Supabase call in the signup flow is a single POST in the setup wizard, which is unavoidable and O(1).

## Testing

- Path B: matched email auto-connects correctly
- Path B: mismatched email falls through to setup wizard (Path C), no crash
- `createUser`: 422 with user ID in body still returns `created: false` correctly
- `createUser`: 422 without user ID now throws instead of paginating
- No regression on Path A (reinstall) or Path C (fresh install)
