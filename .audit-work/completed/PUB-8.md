---
item_id: '#PUB-8'
title: Welcome notification hardcodes stale product name "Sight" after the Partna
  rebrand
source: pilot-stage-3.md
tier: P3
effort_estimate: S
completed_at: '2026-05-08T02:36:12+00:00'
mode: overnight
commit_sha: d555f34
files_touched:
- app/Http/Controllers/Api/PublicSite/BootstrapController.php
test_result: pass
questions_asked: 0
---

# #PUB-8 — Welcome notification hardcodes stale product name "Sight" after the Partna rebrand

## Plain English

Every new professional who signed up got a welcome notification that said "Welcome to Sight" — a name the product hasn't used since the Partna rebrand. The fix updates the title to "Welcome to Partna" and replaces the placeholder body ("This is placeholder content for now.") with a real onboarding message directing users to complete their profile and connect with brands. No existing real-user data needed updating because the project is pre-pilot with no live customer accounts.

## Technical Summary

Changed `createWelcomeNotification` in `app/Http/Controllers/Api/PublicSite/BootstrapController.php` (line ~310):
- `title` key: `'Welcome to Sight'` → `'Welcome to Partna'` (affects `firstOrCreate` lookup key)
- `body` value: `'Welcome to Sight. This is placeholder content for now.'` → `'Your account is ready. Complete your profile, connect with brands, and start tracking your commissions — all from your dashboard.'`

The `firstOrCreate` lookup key changes with the title, meaning any existing rows keyed on "Welcome to Sight" will not be updated by this code change — a new row would be created on next bootstrap for those accounts. No SQL migration was run; the project is pre-pilot with no real user accounts.

## Decisions Made

- **Body copy**: Wrote product-appropriate onboarding copy referencing profile completion, brand connections, and commission tracking — the three core affiliate actions in Partna. The audit said "real onboarding copy" without specifying text, so I inferred from the product domain.
- **No SQL migration**: Skipped the one-off `UPDATE` for existing rows. Per project memory the platform is pre-beta with no real customers, so there are no stale rows to fix. The audit item noted this was only needed "if any real accounts were created."

## Notes

The rebrand commit `fddeec5` updated "Side St" → "Partna" across the codebase but missed the "Sight" placeholder here — "Sight" appears to be a pre-rebrand product name (not "Side St"), which is why the automated rename didn't catch it.

## Questions Asked
(none)
