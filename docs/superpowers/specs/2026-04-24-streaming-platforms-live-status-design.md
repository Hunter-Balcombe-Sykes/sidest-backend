# Streaming Platforms & Live Status Design

**Date:** 2026-04-24  
**Status:** Approved  
**Scope:** Add Twitch and Kick as linkable platforms; auto-detect live status and surface it on public profiles.

---

## Overview

Two new streaming platforms (Twitch, Kick) are added to the existing platform registry in `config/sidest.php`. This requires zero changes to the `SocialLinkNormalizer` service or DB schema. A per-link toggle (`live_check_enabled`) enables automatic live status detection. A background polling job queries platform APIs, stores results in Redis, and injects `is_live` into public profile responses at read time — with no DB writes in the hot path.

---

## 1. Platform Config

### New entries in `config/sidest.php`

Add `twitch` and `kick` to the platform registry using the same structure as existing platforms:

```php
'twitch' => [
    'display_name'       => 'Twitch',
    'icon_key'           => 'twitch',
    'placeholder'        => 'your channel name',
    'handle_pattern'     => '/^[a-zA-Z0-9_]{4,25}$/',
    'url_template'       => 'https://twitch.tv/{handle}',
    'host_allowlist'     => ['twitch.tv', 'www.twitch.tv'],
    'url_path_extractor' => '#^/([a-zA-Z0-9_]{4,25})/?$#',
    'handle_location'    => 'path',
    'default_category'   => 'streaming',
],
'kick' => [
    'display_name'       => 'Kick',
    'icon_key'           => 'kick',
    'placeholder'        => 'your channel name',
    'handle_pattern'     => '/^[a-zA-Z0-9_-]{3,50}$/',
    'url_template'       => 'https://kick.com/{handle}',
    'host_allowlist'     => ['kick.com', 'www.kick.com'],
    'url_path_extractor' => '#^/([a-zA-Z0-9_-]{3,50})/?$#',
    'handle_location'    => 'path',
    'default_category'   => 'streaming',
],
```

Add `'streaming'` to `link_categories`.

The `streaming_platforms` array lists which platforms support live status checking:

```php
'streaming_platforms' => ['twitch', 'kick'],
```

### New env vars (added to `.env.example`)

```
TWITCH_CLIENT_ID=
TWITCH_CLIENT_SECRET=
KICK_CLIENT_ID=
KICK_CLIENT_SECRET=
```

---

## 2. Data Model

### No DB migration required

The existing `site.blocks` table with its `settings` JSONB column accommodates all new fields.

### Block settings shape (Twitch/Kick links)

```json
{
  "platform": "twitch",
  "handle": "shroud",
  "category": "streaming",
  "live_check_enabled": false,
  "open_in_new_tab": true,
  "highlight": false,
  "note": null
}
```

`live_check_enabled` (boolean, default `false`) is the only new persistent field. It is:
- Settable only via `PATCH /links/{linkBlock}` (not on creation)
- Only valid when `platform` is in `config('sidest.streaming_platforms')`
- Validated as strict boolean in `UpdateLinkBlockRequest`
- Rejected if submitted for non-streaming platforms

### Live status — Redis only

```
streaming:live:twitch:{handle}   → "1" (live) or "0" (offline), TTL 3 minutes
streaming:live:kick:{handle}     → "1" (live) or "0" (offline), TTL 3 minutes
```

`is_live` is **never stored in the DB**. It is injected at response time from Redis. Missing key = `false` (safe default).

### DB index (Supabase migration)

```sql
CREATE INDEX idx_blocks_live_check
    ON site.blocks ((settings->>'live_check_enabled'))
    WHERE block_type = 'link';
```

---

## 3. Polling Job

**Class:** `App\Jobs\Streaming\CheckStreamingLiveStatusJob`

**Schedule:** Every 2 minutes via `app/Console/Kernel.php` (or `routes/console.php`), with `withoutOverlapping(5)` to prevent stacking.

### Flow

1. Query `site.blocks` in chunks of 500 where `block_type = 'link'` and `settings->>'live_check_enabled' = 'true'` and `deleted_at IS NULL`.
2. Group handles by platform. Deduplicate handles within each platform — same handle shared by two professionals = one API call.
3. Skip any handle whose Redis key has TTL > 60 seconds remaining (still fresh).
4. **Twitch:** batch deduplicated handles into groups of 100; call `GET https://api.twitch.tv/helix/streams?user_login[]=...` per group. Write results to Redis.
5. **Kick:** call one request per handle (`GET https://api.kick.com/v1/channels/{handle}`). On 429 or persistent error, abort remaining Kick handles for this cycle (see error handling).
6. Write results to Redis with 3-minute TTL.

### Token management

Tokens are stored in Redis:
```
streaming:token:twitch   → bearer token string, TTL = (expires_in - 300) seconds
streaming:token:kick     → bearer token string, TTL = (expires_in - 300) seconds
```

Before each poll cycle, the job fetches the token from Redis. If missing or expiring:
- Acquires a Redis `SET NX` lock (`streaming:token:refresh:twitch`) to prevent concurrent refreshes
- Fetches a new Client Credentials token from the platform
- Writes it to Redis with TTL set 5 minutes before actual expiry
- Releases lock

Token is never logged, stored in DB, or returned in any response.

---

## 4. Error Handling & Observability

All errors are logged via Laravel's standard logging; Nightwatch surfaces them automatically.

| Condition | Action | Log level |
|---|---|---|
| HTTP 429 from Kick | Abort Kick batch for this cycle; set `streaming:kick:rate_limited` Redis key (TTL 5 min); log handle + `retry_after` header | `warning` |
| `streaming:kick:rate_limited` key present at cycle start | Skip Kick entirely; log "skipping Kick — rate limited from previous cycle" | `warning` |
| HTTP 5xx or timeout from either platform | Log platform, handle, status, response body; continue to next handle | `error` |
| HTTP 401 / credential failure | Log platform; skip entire platform for this cycle | `critical` |
| Unexpected response shape (missing fields) | Log platform, handle, raw response; treat as offline | `error` |
| Redis unavailable | Catch exception; log; abort job gracefully — no DB fallback attempted | `critical` |

No user-facing impact on any failure path — `is_live` defaults to `false`.

---

## 5. API Response

### Injection point

Public profile data flows through `SiteCacheService::getPublicSitePayload()`, which caches the full site payload for 15 minutes. `is_live` must **not** be stored inside this cache — it would make live status stale for up to 15 minutes and pollute the long-lived cache with ephemeral data.

Instead, a new `App\Services\Streaming\LiveStatusInjector` service post-processes the `links` array **after** the cache is read, immediately before the controller returns the response. The public site controller calls `$injector->injectIntoBlocks(array $links): array`, which loops over blocks, checks Redis for any with `live_check_enabled = true` and a streaming platform, and appends `is_live`.

The `SiteCacheService` itself is not modified — the cache payload stays clean.

### Response shape (public profile links array)

```json
{
  "id": "uuid",
  "platform": "twitch",
  "handle": "shroud",
  "url": "https://twitch.tv/shroud",
  "category": "streaming",
  "live_check_enabled": true,
  "is_live": true
}
```

`is_live` is injected at read time. It is:
- Only appended to blocks where `live_check_enabled = true` and platform is in `streaming_platforms`
- Never writable by clients (filtered from allowed `settings` keys in request validation)
- Derived entirely from Redis — no DB read for this field
- Absent (not `false`) on non-streaming links, keeping the response shape clean

---

## 6. Security

| Concern | Mitigation |
|---|---|
| SSRF | API URLs are hardcoded constants — never derived from user input |
| Credential exposure | Tokens stored in Redis only; never in DB, logs, or responses |
| Token race condition | `SET NX` atomic lock prevents concurrent refresh |
| Client forging `is_live` | Field is read-only; removed from allowed request settings keys |
| `live_check_enabled` on non-streaming platforms | Validated in `UpdateLinkBlockRequest` — rejected if platform not in `streaming_platforms` |
| Handle injection | Existing ASCII-only `handle_pattern` validation in `SocialLinkNormalizer` applies |
| Phishing via host spoofing | Existing `host_allowlist` per platform prevents lookalike domains |

---

## 7. Scalability

| Factor | Detail |
|---|---|
| Twitch API calls | Batched 100/request → 2,000 streamers = 20 API calls/cycle; limit ~800 req/min |
| Kick API calls | 1 per handle; mitigated by per-cycle rate limit abort + skip on subsequent cycle |
| Redis memory | ~100 bytes/key → 2,000 keys ≈ 200 KB; negligible |
| DB reads | One chunked query per cycle (500-row pages); no DB writes in hot path |
| Handle deduplication | Shared channels (same handle, multiple professionals) produce one API call |
| TTL skip | Handles with fresh Redis entries are skipped mid-cycle, reducing redundant calls |
| Job overlap | `withoutOverlapping(5)` prevents stacking on slow cycles |
| Idempotency | Writing same value to Redis twice is a no-op; token refresh is atomic |

---

## 8. Files to Create / Modify

| Action | File |
|---|---|
| Modify | `config/sidest.php` — add `twitch`, `kick` entries; add `streaming` to `link_categories`; add `streaming_platforms` key |
| Modify | `.env.example` — add 4 new credential keys |
| Modify | `app/Http/Requests/Api/Professional/Site/UpdateLinkBlockRequest.php` — allow `live_check_enabled`; reject for non-streaming platforms |
| Modify | Public site controller that returns profile payload — call `LiveStatusInjector::injectIntoBlocks()` after cache read |
| Create | `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php` |
| Create | `app/Services/Streaming/TwitchApiClient.php` |
| Create | `app/Services/Streaming/KickApiClient.php` |
| Create | `app/Services/Streaming/StreamingTokenManager.php` — handles token refresh + Redis storage with `SET NX` lock |
| Create | `app/Services/Streaming/LiveStatusPoller.php` — orchestrates batching, deduplication, Redis writes |
| Create | `app/Services/Streaming/LiveStatusInjector.php` — post-processes blocks array, injects `is_live` from Redis |
| Modify | `routes/console.php` — schedule `CheckStreamingLiveStatusJob` every 2 min with `withoutOverlapping(5)` |
| Create | `supabase/migrations/{timestamp}_add_live_check_index.sql` — expression index on `settings->>'live_check_enabled'` WHERE `block_type = 'link'` |
| Create | `tests/Feature/Streaming/CheckStreamingLiveStatusJobTest.php` |
| Create | `tests/Unit/Streaming/LiveStatusPollerTest.php` |
