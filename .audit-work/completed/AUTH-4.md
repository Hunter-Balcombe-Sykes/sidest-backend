---
item_id: '#AUTH-4'
title: IntegrationPolicy::view returns a bare `false` (yields HTTP 403) instead of
  `denyAsNotFound()` (HTTP 404) when integration has no owner
source: pilot-stage-3.md
tier: P2
effort_estimate: S
completed_at: '2026-05-08T02:05:11+00:00'
mode: overnight
commit_sha: 2fa308a
files_touched:
- app/Policies/IntegrationPolicy.php
- tests/Unit/Policies/IntegrationPolicyTest.php
test_result: pass
questions_asked: 0
---

# #AUTH-4 — IntegrationPolicy::view returns a bare `false` (yields HTTP 403) instead of `denyAsNotFound()` (HTTP 404) when integration has no owner

## Plain English

When someone tried to look up an integration record that had no owner assigned (a data integrity edge case), the system was responding with "you can't see that" (403), which accidentally told the caller that a record with that ID really exists. The fix makes it respond with "never heard of it" (404) instead — the same thing every other locked door in the system says. An attacker probing UUIDs can no longer use the status code difference to confirm a record exists.

## Technical Summary

`IntegrationPolicy::actorCanReachOwner` previously returned a plain `bool`, which meant the empty-`professional_id` branch returned `false` and Laravel's Gate translated that to HTTP 403. Changed the return type to `bool|Response` and replaced `return false` with `return $this->denyAsNotFound()` (inherited from `BasePolicy`). Updated `view()`'s return type annotation from `bool` to `bool|Response` to match; `manage()` already had the correct union type and served as the in-file template. Updated the unit test `it('denies view when the integration has no professional_id')` to assert a `Response` instance with status 404 instead of `toBeFalse()`.

Files changed:
- `app/Policies/IntegrationPolicy.php` — return type + denyAsNotFound
- `tests/Unit/Policies/IntegrationPolicyTest.php` — updated assertion

## Decisions Made

- Updated the existing test rather than adding a new one: the old assertion was simply wrong given the new contract, so replacing it in-place is cleaner than having two tests for the same path.

## Notes

The `manage` method delegates to the same `actorCanReachOwner` helper, so it also inherits the 404 behavior for the empty-ownerId path as a side effect — this is correct and consistent with the authorization doctrine.

## Questions Asked
(none)
