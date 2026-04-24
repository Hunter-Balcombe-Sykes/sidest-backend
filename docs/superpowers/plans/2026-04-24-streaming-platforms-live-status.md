# Streaming Platforms & Live Status Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Twitch and Kick as linkable streaming platforms and auto-detect live status via background API polling, surfacing an `is_live` flag on public profile responses.

**Architecture:** Platform config drives normalisation generically (no service changes); a scheduled job polls Twitch (batched) and Kick (per-handle) every 2 minutes and writes results to Redis only; `LiveStatusInjector` post-processes the cached site payload at read time so the 15-minute site cache stays clean.

**Tech Stack:** Laravel 12, PHP 8.2, Redis (`Illuminate\Support\Facades\Redis`), Pest 4, Laravel HTTP client (`Illuminate\Support\Facades\Http`)

---

## File Map

| Action | Path | Responsibility |
|---|---|---|
| Modify | `config/sidest.php` | Add `twitch`/`kick` to `social_platforms`; add `streaming` to `link_categories`; add `live_check_enabled` to `link_block_settings_keys`; add `streaming_platforms` key |
| Modify | `.env.example` | Add `TWITCH_CLIENT_ID`, `TWITCH_CLIENT_SECRET`, `KICK_CLIENT_ID`, `KICK_CLIENT_SECRET` |
| Create | `supabase/migrations/{ts}_add_live_check_index.sql` | Expression index on `settings->>'live_check_enabled'` WHERE `block_type = 'link'` |
| Modify | `app/Http/Requests/Api/Professional/Site/UpdateLinkBlockRequest.php` | Allow `settings.live_check_enabled` (boolean); reject on non-streaming platforms |
| Create | `app/Services/Streaming/StreamingTokenManager.php` | Token fetch, Redis cache, atomic NX refresh lock |
| Create | `app/Services/Streaming/TwitchApiClient.php` | Batched `/helix/streams` calls; returns live handle set |
| Create | `app/Services/Streaming/KickApiClient.php` | Per-handle channel check; throws `KickRateLimitException` on 429 |
| Create | `app/Exceptions/Streaming/KickRateLimitException.php` | Typed exception for Kick 429 |
| Create | `app/Services/Streaming/LiveStatusPoller.php` | Deduplication, TTL skip, batch grouping, Redis writes |
| Create | `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php` | DB chunk query, error handling, delegates to `LiveStatusPoller` |
| Create | `app/Services/Streaming/LiveStatusInjector.php` | Post-processes cached payload; injects `is_live` from Redis |
| Modify | `app/Http/Controllers/Api/PublicSite/PublicSiteController.php` | Wire `LiveStatusInjector` into `show()` and `showByHeader()` |
| Modify | `routes/console.php` | Schedule `CheckStreamingLiveStatusJob` every 2 min |
| Create | `tests/Unit/Streaming/LiveStatusPollerTest.php` | Unit: dedup, TTL skip, batching, Redis writes |
| Create | `tests/Unit/Streaming/LiveStatusInjectorTest.php` | Unit: payload injection, passthrough, missing key default |
| Create | `tests/Unit/Streaming/CheckStreamingLiveStatusJobTest.php` | Unit: job orchestration, rate limit abort, error logging (DB query bypassed — PostgreSQL-only JSONB operator) |

---

### Task 1: Platform Config

**Files:**
- Modify: `config/sidest.php`
- Modify: `.env.example`

- [ ] **Step 1: Add Twitch and Kick to `social_platforms` in `config/sidest.php`**

In `config/sidest.php`, after the last platform entry (currently `bandcamp`, ending around the `],` that closes the `social_platforms` array), add before that closing `],`:

```php
        // --- Streaming platforms ---
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

Also add `'twitch'` and `'kick'` to the icon keys array near the top of the file (the array that lists icon names).

- [ ] **Step 2: Add `streaming` to `link_categories` and `live_check_enabled` to `link_block_settings_keys`**

Change:
```php
'link_categories' => ['social', 'booking', 'education', 'content', 'events', 'other'],
```
To:
```php
'link_categories' => ['social', 'booking', 'education', 'content', 'events', 'streaming', 'other'],
```

In `link_block_settings_keys`, add `'live_check_enabled'` after `'category'`:
```php
'link_block_settings_keys' => [
    'open_in_new_tab',
    'rel_nofollow',
    'rel_sponsored',
    'rel_ugc',
    'highlight',
    'note',
    'platform',
    'handle',
    'category',
    'live_check_enabled',
],
```

- [ ] **Step 3: Add `streaming_platforms` key**

After `link_categories`, add:
```php
// Platforms that support automatic live status detection via the polling job.
// Must match keys in social_platforms above.
'streaming_platforms' => ['twitch', 'kick'],
```

- [ ] **Step 4: Add env vars to `.env.example`**

Add after the existing Stripe/Shopify entries:
```
# Streaming platform API credentials (for live status polling)
TWITCH_CLIENT_ID=
TWITCH_CLIENT_SECRET=
KICK_CLIENT_ID=
KICK_CLIENT_SECRET=
```

- [ ] **Step 5: Verify existing normalizer tests still pass**

```bash
php artisan config:clear && composer test -- --filter=SocialLink
```
Expected: All green. The normalizer handles new platforms generically — no code changes needed there.

- [ ] **Step 6: Commit**

```bash
git add config/sidest.php .env.example
git commit -m "feat(streaming): add Twitch and Kick to platform registry"
```

---

### Task 2: DB Index Migration

**Files:**
- Create: `supabase/migrations/20260424120000_add_live_check_index.sql`

- [ ] **Step 1: Create the migration file**

```sql
-- Expression index to accelerate the polling job's query:
--   WHERE block_type = 'link' AND settings->>'live_check_enabled' = 'true'
-- Without this, the job scans all link blocks on every 2-minute cycle.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_blocks_live_check_enabled
    ON site.blocks ((settings->>'live_check_enabled'))
    WHERE block_type = 'link' AND deleted_at IS NULL;
```

- [ ] **Step 2: Commit**

```bash
git add supabase/migrations/20260424120000_add_live_check_index.sql
git commit -m "feat(streaming): add expression index for live_check_enabled polling query"
```

---

### Task 3: UpdateLinkBlockRequest — live_check_enabled

**Files:**
- Modify: `app/Http/Requests/Api/Professional/Site/UpdateLinkBlockRequest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/UpdateLinkBlockLiveCheckTest.php`:

```php
<?php

/** @phpstan-ignore-all */

use App\Http\Requests\Api\Professional\Site\UpdateLinkBlockRequest;
use Illuminate\Support\Facades\Validator;

it('accepts live_check_enabled=true in settings for a streaming platform context', function () {
    config(['sidest.streaming_platforms' => ['twitch', 'kick']]);
    config(['sidest.link_block_settings_keys' => [
        'platform', 'handle', 'category', 'highlight', 'note',
        'open_in_new_tab', 'rel_nofollow', 'rel_sponsored', 'rel_ugc',
        'live_check_enabled',
    ]]);

    $request = new UpdateLinkBlockRequest;
    $validator = Validator::make(
        ['settings' => ['live_check_enabled' => true]],
        $request->rules()
    );

    expect($validator->fails())->toBeFalse();
});

it('rejects live_check_enabled as a non-boolean', function () {
    config(['sidest.link_block_settings_keys' => [
        'platform', 'handle', 'category', 'highlight', 'note',
        'open_in_new_tab', 'rel_nofollow', 'rel_sponsored', 'rel_ugc',
        'live_check_enabled',
    ]]);

    $request = new UpdateLinkBlockRequest;
    $validator = Validator::make(
        ['settings' => ['live_check_enabled' => 'yes']],
        $request->rules()
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('settings.live_check_enabled'))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
composer test -- --filter=UpdateLinkBlockLiveCheck
```
Expected: FAIL — `settings.live_check_enabled` rule not yet defined.

- [ ] **Step 3: Add the rule and `is_live` rejection test to the test file**

Add a third test to `tests/Feature/Api/UpdateLinkBlockLiveCheckTest.php`:

```php
it('rejects is_live in settings — it is read-only and not in the allowlist', function () {
    config(['sidest.link_block_settings_keys' => [
        'platform', 'handle', 'category', 'highlight', 'note',
        'open_in_new_tab', 'rel_nofollow', 'rel_sponsored', 'rel_ugc',
        'live_check_enabled',
    ]]);

    // is_live is NOT in link_block_settings_keys — the withValidator allowlist check rejects it
    $request = new UpdateLinkBlockRequest;
    $data = ['settings' => ['is_live' => true]];

    // Simulate the withValidator allowlist check directly
    $validator = Validator::make($data, $request->rules());
    $request->withValidator($validator);

    // The settings allowlist in withValidator adds an error for unknown keys
    expect($validator->errors()->has('settings'))->toBeTrue();
});
```

- [ ] **Step 4: Add the rule to the request**

In `UpdateLinkBlockRequest::rules()`, add after `'settings.category'`:

```php
'settings.live_check_enabled' => ['sometimes', 'boolean'],
```

Note: `is_live` is intentionally NOT added to `link_block_settings_keys` in config. It is read-only and will be rejected by the existing `withValidator` allowlist check if a client attempts to submit it.

- [ ] **Step 5: Run to verify all tests pass**

```bash
composer test -- --filter=UpdateLinkBlockLiveCheck
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/Api/Professional/Site/UpdateLinkBlockRequest.php \
        tests/Feature/Api/UpdateLinkBlockLiveCheckTest.php
git commit -m "feat(streaming): allow live_check_enabled toggle in UpdateLinkBlockRequest"
```

---

### Task 4: KickRateLimitException

**Files:**
- Create: `app/Exceptions/Streaming/KickRateLimitException.php`

- [ ] **Step 1: Create the exception**

```php
<?php

namespace App\Exceptions\Streaming;

use RuntimeException;

/** Thrown by KickApiClient when Kick returns HTTP 429. */
class KickRateLimitException extends RuntimeException
{
    public function __construct(
        public readonly ?int $retryAfter = null
    ) {
        parent::__construct('Kick API rate limit exceeded.');
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Exceptions/Streaming/KickRateLimitException.php
git commit -m "feat(streaming): add KickRateLimitException"
```

---

### Task 5: StreamingTokenManager

**Files:**
- Create: `app/Services/Streaming/StreamingTokenManager.php`
- Create: `tests/Unit/Streaming/StreamingTokenManagerTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Streaming/StreamingTokenManagerTest.php`:

```php
<?php

/** @phpstan-ignore-all */

use App\Services\Streaming\StreamingTokenManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
});

it('fetches a Twitch token and caches it in Redis', function () {
    config([
        'services.twitch.client_id' => 'test-id',
        'services.twitch.client_secret' => 'test-secret',
    ]);

    Http::fake([
        'id.twitch.tv/oauth2/token' => Http::response([
            'access_token' => 'twitch-token-abc',
            'expires_in' => 3600,
        ], 200),
    ]);

    $manager = new StreamingTokenManager;
    $token = $manager->getToken('twitch');

    expect($token)->toBe('twitch-token-abc');
    expect(Redis::exists('streaming:token:twitch'))->toBe(1);
});

it('returns cached Twitch token without making an HTTP call', function () {
    Redis::set('streaming:token:twitch', 'cached-token', 'EX', 3600);

    Http::fake(); // no calls expected

    $manager = new StreamingTokenManager;
    $token = $manager->getToken('twitch');

    expect($token)->toBe('cached-token');
    Http::assertNothingSent();
});

it('returns null if credentials are missing', function () {
    config(['services.twitch.client_id' => null]);
    config(['services.twitch.client_secret' => null]);

    $manager = new StreamingTokenManager;
    $token = $manager->getToken('twitch');

    expect($token)->toBeNull();
});

it('fetches a Kick token and caches it', function () {
    config([
        'services.kick.client_id' => 'kick-id',
        'services.kick.client_secret' => 'kick-secret',
    ]);

    Http::fake([
        'id.kick.com/oauth/token' => Http::response([
            'access_token' => 'kick-token-xyz',
            'expires_in' => 3600,
        ], 200),
    ]);

    $manager = new StreamingTokenManager;
    $token = $manager->getToken('kick');

    expect($token)->toBe('kick-token-xyz');
});
```

- [ ] **Step 2: Run to verify they fail**

```bash
composer test -- --filter=StreamingTokenManagerTest
```
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement StreamingTokenManager**

```php
<?php

namespace App\Services\Streaming;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Manages OAuth Client Credentials tokens for Twitch and Kick.
 * Tokens are stored in Redis with TTL = (expires_in - 300) seconds.
 * A SET NX lock prevents concurrent refreshes.
 */
class StreamingTokenManager
{
    private const TOKEN_KEY_PREFIX = 'streaming:token:';

    private const REFRESH_LOCK_PREFIX = 'streaming:token:refresh:';

    private const EXPIRY_BUFFER_SECONDS = 300;

    /** @var array<string, array{token_url: string, client_id_key: string, client_secret_key: string}> */
    private const PLATFORM_CONFIG = [
        'twitch' => [
            'token_url'         => 'https://id.twitch.tv/oauth2/token',
            'client_id_key'     => 'services.twitch.client_id',
            'client_secret_key' => 'services.twitch.client_secret',
        ],
        'kick' => [
            'token_url'         => 'https://id.kick.com/oauth/token',
            'client_id_key'     => 'services.kick.client_id',
            'client_secret_key' => 'services.kick.client_secret',
        ],
    ];

    /** Returns a valid bearer token for $platform, refreshing if needed. */
    public function getToken(string $platform): ?string
    {
        $cfg = self::PLATFORM_CONFIG[$platform] ?? null;
        if (! $cfg) {
            return null;
        }

        $clientId = config($cfg['client_id_key']);
        $clientSecret = config($cfg['client_secret_key']);
        if (! $clientId || ! $clientSecret) {
            return null;
        }

        $cached = Redis::get(self::TOKEN_KEY_PREFIX.$platform);
        if ($cached) {
            return $cached;
        }

        return $this->refreshToken($platform, $cfg, (string) $clientId, (string) $clientSecret);
    }

    /** @param array<string, string> $cfg */
    private function refreshToken(string $platform, array $cfg, string $clientId, string $clientSecret): ?string
    {
        // Atomic lock — only one process refreshes at a time.
        $lockKey = self::REFRESH_LOCK_PREFIX.$platform;
        $locked = Redis::set($lockKey, '1', 'EX', 30, 'NX');

        if (! $locked) {
            // Another process is refreshing — wait briefly and read what they wrote.
            usleep(500_000);

            return Redis::get(self::TOKEN_KEY_PREFIX.$platform) ?: null;
        }

        try {
            $response = Http::asForm()->post($cfg['token_url'], [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'grant_type'    => 'client_credentials',
            ]);

            if (! $response->successful()) {
                Log::critical('streaming.auth_failure', [
                    'platform' => $platform,
                    'status'   => $response->status(),
                ]);

                return null;
            }

            $token = $response->json('access_token');
            $expiresIn = (int) ($response->json('expires_in') ?? 3600);
            $ttl = max(60, $expiresIn - self::EXPIRY_BUFFER_SECONDS);

            Redis::set(self::TOKEN_KEY_PREFIX.$platform, $token, 'EX', $ttl);

            return $token;
        } catch (\Throwable $e) {
            Log::critical('streaming.auth_failure', [
                'platform' => $platform,
                'message'  => $e->getMessage(),
            ]);

            return null;
        } finally {
            Redis::del($lockKey);
        }
    }
}
```

Also add to `config/services.php`:

```php
'twitch' => [
    'client_id'     => env('TWITCH_CLIENT_ID'),
    'client_secret' => env('TWITCH_CLIENT_SECRET'),
],
'kick' => [
    'client_id'     => env('KICK_CLIENT_ID'),
    'client_secret' => env('KICK_CLIENT_SECRET'),
],
```

- [ ] **Step 4: Run to verify tests pass**

```bash
composer test -- --filter=StreamingTokenManagerTest
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Streaming/StreamingTokenManager.php \
        config/services.php \
        tests/Unit/Streaming/StreamingTokenManagerTest.php
git commit -m "feat(streaming): add StreamingTokenManager with atomic Redis token refresh"
```

---

### Task 6: TwitchApiClient

**Files:**
- Create: `app/Services/Streaming/TwitchApiClient.php`
- Create: `tests/Unit/Streaming/TwitchApiClientTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Streaming/TwitchApiClientTest.php`:

```php
<?php

/** @phpstan-ignore-all */

use App\Services\Streaming\StreamingTokenManager;
use App\Services\Streaming\TwitchApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('returns handles that are currently live', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('twitch')->andReturn('test-token');

    Http::fake([
        'api.twitch.tv/helix/streams*' => Http::response([
            'data' => [
                ['user_login' => 'shroud', 'type' => 'live'],
                ['user_login' => 'ninja', 'type' => 'live'],
            ],
        ], 200),
    ]);

    $client = new TwitchApiClient($manager);
    $liveHandles = $client->getLiveHandles(['shroud', 'ninja', 'offlineuser']);

    expect($liveHandles)->toBe(['shroud', 'ninja']);
});

it('returns empty array when no handles are live', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('twitch')->andReturn('test-token');

    Http::fake([
        'api.twitch.tv/helix/streams*' => Http::response(['data' => []], 200),
    ]);

    $client = new TwitchApiClient($manager);
    $liveHandles = $client->getLiveHandles(['offline1', 'offline2']);

    expect($liveHandles)->toBe([]);
});

it('logs an error and returns empty array on 5xx response', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('twitch')->andReturn('test-token');

    Http::fake([
        'api.twitch.tv/helix/streams*' => Http::response([], 500),
    ]);

    Log::shouldReceive('error')->once()->with('streaming.api_error', Mockery::any());

    $client = new TwitchApiClient($manager);
    $liveHandles = $client->getLiveHandles(['someuser']);

    expect($liveHandles)->toBe([]);
});

it('logs critical and returns empty array when token is unavailable', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('twitch')->andReturn(null);

    Log::shouldReceive('critical')->once()->with('streaming.auth_failure', Mockery::any());

    $client = new TwitchApiClient($manager);
    $liveHandles = $client->getLiveHandles(['someuser']);

    expect($liveHandles)->toBe([]);
});

it('sends the correct authorization headers', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('twitch')->andReturn('bearer-token');

    config(['services.twitch.client_id' => 'my-client-id']);

    Http::fake([
        'api.twitch.tv/helix/streams*' => Http::response(['data' => []], 200),
    ]);

    $client = new TwitchApiClient($manager);
    $client->getLiveHandles(['anyuser']);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer bearer-token')
            && $request->hasHeader('Client-ID', 'my-client-id');
    });
});
```

- [ ] **Step 2: Run to verify they fail**

```bash
composer test -- --filter=TwitchApiClientTest
```
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement TwitchApiClient**

```php
<?php

namespace App\Services\Streaming;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls the Twitch Helix streams endpoint to check live status.
 * Accepts up to 100 handles per request (Twitch API limit).
 */
class TwitchApiClient
{
    private const STREAMS_URL = 'https://api.twitch.tv/helix/streams';

    public function __construct(
        private StreamingTokenManager $tokens
    ) {}

    /**
     * Returns the subset of $handles that are currently live on Twitch.
     * Max 100 handles per call — caller (LiveStatusPoller) must batch.
     *
     * @param  string[]  $handles
     * @return string[]
     */
    public function getLiveHandles(array $handles): array
    {
        if (empty($handles)) {
            return [];
        }

        $token = $this->tokens->getToken('twitch');
        if (! $token) {
            Log::critical('streaming.auth_failure', ['platform' => 'twitch']);

            return [];
        }

        // Twitch requires repeated query params: ?user_login=a&user_login=b
        // http_build_query flattens arrays, so build manually.
        $query = implode('&', array_map(
            fn ($h) => 'user_login='.urlencode($h),
            $handles
        ));

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Client-ID'     => (string) config('services.twitch.client_id'),
            ])->get(self::STREAMS_URL.'?'.$query);

            if (! $response->successful()) {
                Log::error('streaming.api_error', [
                    'platform' => 'twitch',
                    'status'   => $response->status(),
                    'body'     => $response->body(),
                ]);

                return [];
            }

            $data = $response->json('data', []);

            return array_values(array_column(
                array_filter($data, fn ($s) => ($s['type'] ?? '') === 'live'),
                'user_login'
            ));
        } catch (\Throwable $e) {
            Log::error('streaming.api_error', [
                'platform' => 'twitch',
                'message'  => $e->getMessage(),
            ]);

            return [];
        }
    }
}
```

- [ ] **Step 4: Run to verify tests pass**

```bash
composer test -- --filter=TwitchApiClientTest
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Streaming/TwitchApiClient.php \
        tests/Unit/Streaming/TwitchApiClientTest.php
git commit -m "feat(streaming): add TwitchApiClient with batched live-status check"
```

---

### Task 7: KickApiClient

**Files:**
- Create: `app/Services/Streaming/KickApiClient.php`
- Create: `tests/Unit/Streaming/KickApiClientTest.php`

> **Note:** Kick's API is at `https://api.kick.com/v1/`. Verify current endpoint shape at `https://dev.kick.com` before implementation. The response field for live status is `livestream` (non-null = live, null = offline).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Streaming/KickApiClientTest.php`:

```php
<?php

/** @phpstan-ignore-all */

use App\Exceptions\Streaming\KickRateLimitException;
use App\Services\Streaming\KickApiClient;
use App\Services\Streaming\StreamingTokenManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('returns true when the channel has an active livestream', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('kick')->andReturn('kick-token');

    Http::fake([
        'api.kick.com/v1/channels/shroud' => Http::response([
            'livestream' => ['id' => 123, 'is_live' => true],
        ], 200),
    ]);

    $client = new KickApiClient($manager);
    expect($client->isLive('shroud'))->toBeTrue();
});

it('returns false when livestream is null', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('kick')->andReturn('kick-token');

    Http::fake([
        'api.kick.com/v1/channels/shroud' => Http::response([
            'livestream' => null,
        ], 200),
    ]);

    $client = new KickApiClient($manager);
    expect($client->isLive('shroud'))->toBeFalse();
});

it('throws KickRateLimitException on 429 with retry-after header', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('kick')->andReturn('kick-token');

    Http::fake([
        'api.kick.com/v1/channels/*' => Http::response([], 429, ['Retry-After' => '60']),
    ]);

    $client = new KickApiClient($manager);

    expect(fn () => $client->isLive('someuser'))
        ->toThrow(KickRateLimitException::class);
});

it('returns false and logs error on 5xx', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('kick')->andReturn('kick-token');

    Http::fake([
        'api.kick.com/v1/channels/*' => Http::response([], 500),
    ]);

    Log::shouldReceive('error')->once()->with('streaming.api_error', Mockery::any());

    $client = new KickApiClient($manager);
    expect($client->isLive('someuser'))->toBeFalse();
});

it('returns false and logs critical when token is unavailable', function () {
    $manager = Mockery::mock(StreamingTokenManager::class);
    $manager->shouldReceive('getToken')->with('kick')->andReturn(null);

    Log::shouldReceive('critical')->once()->with('streaming.auth_failure', Mockery::any());

    $client = new KickApiClient($manager);
    expect($client->isLive('someuser'))->toBeFalse();
});
```

- [ ] **Step 2: Run to verify they fail**

```bash
composer test -- --filter=KickApiClientTest
```
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement KickApiClient**

```php
<?php

namespace App\Services\Streaming;

use App\Exceptions\Streaming\KickRateLimitException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Checks live status for a single Kick channel.
 * Throws KickRateLimitException on 429 so the poller can abort the cycle.
 *
 * Endpoint reference: https://dev.kick.com — verify if endpoint shape changes.
 */
class KickApiClient
{
    private const CHANNELS_URL = 'https://api.kick.com/v1/channels';

    public function __construct(
        private StreamingTokenManager $tokens
    ) {}

    /**
     * Returns true if the channel is currently live on Kick.
     *
     * @throws KickRateLimitException when Kick returns 429
     */
    public function isLive(string $handle): bool
    {
        $token = $this->tokens->getToken('kick');
        if (! $token) {
            Log::critical('streaming.auth_failure', ['platform' => 'kick']);

            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])->get(self::CHANNELS_URL.'/'.urlencode($handle));

            if ($response->status() === 429) {
                $retryAfter = (int) ($response->header('Retry-After') ?? 60);
                throw new KickRateLimitException($retryAfter);
            }

            if (! $response->successful()) {
                Log::error('streaming.api_error', [
                    'platform' => 'kick',
                    'handle'   => $handle,
                    'status'   => $response->status(),
                    'body'     => $response->body(),
                ]);

                return false;
            }

            return $response->json('livestream') !== null;
        } catch (KickRateLimitException $e) {
            throw $e; // let poller handle it
        } catch (\Throwable $e) {
            Log::error('streaming.api_error', [
                'platform' => 'kick',
                'handle'   => $handle,
                'message'  => $e->getMessage(),
            ]);

            return false;
        }
    }
}
```

- [ ] **Step 4: Run to verify tests pass**

```bash
composer test -- --filter=KickApiClientTest
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Streaming/KickApiClient.php \
        tests/Unit/Streaming/KickApiClientTest.php
git commit -m "feat(streaming): add KickApiClient with rate limit exception"
```

---

### Task 8: LiveStatusPoller

**Files:**
- Create: `app/Services/Streaming/LiveStatusPoller.php`
- Create: `tests/Unit/Streaming/LiveStatusPollerTest.php`

Redis key format: `streaming:live:{platform}:{handle}` → `"1"` or `"0"`, TTL 180 seconds.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Streaming/LiveStatusPollerTest.php`:

```php
<?php

/** @phpstan-ignore-all */

use App\Exceptions\Streaming\KickRateLimitException;
use App\Services\Streaming\KickApiClient;
use App\Services\Streaming\LiveStatusPoller;
use App\Services\Streaming\TwitchApiClient;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::flushdb());

it('writes live=1 to Redis for a Twitch handle that is live', function () {
    $twitch = Mockery::mock(TwitchApiClient::class);
    $twitch->shouldReceive('getLiveHandles')
        ->with(['shroud'])
        ->andReturn(['shroud']);

    $kick = Mockery::mock(KickApiClient::class);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('twitch', ['shroud']);

    expect(Redis::get('streaming:live:twitch:shroud'))->toBe('1');
});

it('writes live=0 to Redis for a Twitch handle that is offline', function () {
    $twitch = Mockery::mock(TwitchApiClient::class);
    $twitch->shouldReceive('getLiveHandles')
        ->with(['offlineuser'])
        ->andReturn([]);

    $kick = Mockery::mock(KickApiClient::class);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('twitch', ['offlineuser']);

    expect(Redis::get('streaming:live:twitch:offlineuser'))->toBe('0');
});

it('deduplicates handles before calling Twitch API', function () {
    $twitch = Mockery::mock(TwitchApiClient::class);
    // Should only be called once with unique handles, not twice
    $twitch->shouldReceive('getLiveHandles')
        ->once()
        ->with(['shroud'])
        ->andReturn(['shroud']);

    $kick = Mockery::mock(KickApiClient::class);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('twitch', ['shroud', 'shroud']);
});

it('skips a Twitch handle whose Redis key is still fresh (TTL > 60s)', function () {
    Redis::set('streaming:live:twitch:freshuser', '1', 'EX', 120);

    $twitch = Mockery::mock(TwitchApiClient::class);
    $twitch->shouldReceive('getLiveHandles')
        ->once()
        ->with([]) // freshuser filtered out
        ->andReturn([]);

    $kick = Mockery::mock(KickApiClient::class);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('twitch', ['freshuser']);
});

it('batches Twitch handles in groups of 100', function () {
    $handles = array_map(fn ($i) => "user{$i}", range(1, 150));

    $twitch = Mockery::mock(TwitchApiClient::class);
    // First batch of 100, second batch of 50
    $twitch->shouldReceive('getLiveHandles')
        ->twice()
        ->andReturn([]);

    $kick = Mockery::mock(KickApiClient::class);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('twitch', $handles);
});

it('writes live=1 to Redis for a Kick handle that is live', function () {
    $twitch = Mockery::mock(TwitchApiClient::class);

    $kick = Mockery::mock(KickApiClient::class);
    $kick->shouldReceive('isLive')->with('xqc')->andReturn(true);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('kick', ['xqc']);

    expect(Redis::get('streaming:live:kick:xqc'))->toBe('1');
});

it('sets rate_limited Redis key and aborts remaining Kick handles on 429', function () {
    $twitch = Mockery::mock(TwitchApiClient::class);

    $kick = Mockery::mock(KickApiClient::class);
    $kick->shouldReceive('isLive')
        ->with('user1')
        ->andThrow(new KickRateLimitException(60));
    // user2 should NOT be called after the 429
    $kick->shouldNotReceive('isLive')->with('user2');

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('kick', ['user1', 'user2']);

    expect(Redis::exists('streaming:kick:rate_limited'))->toBe(1);
});
```

- [ ] **Step 2: Run to verify they fail**

```bash
composer test -- --filter=LiveStatusPollerTest
```
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement LiveStatusPoller**

```php
<?php

namespace App\Services\Streaming;

use App\Exceptions\Streaming\KickRateLimitException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Polls Twitch and Kick APIs for live status and writes results to Redis.
 * No DB writes — live status is ephemeral.
 *
 * Redis keys: streaming:live:{platform}:{handle} → "1"|"0", TTL 180s
 */
class LiveStatusPoller
{
    private const LIVE_KEY_PREFIX = 'streaming:live:';

    private const KICK_RATE_LIMITED_KEY = 'streaming:kick:rate_limited';

    private const LIVE_TTL_SECONDS = 180;

    private const TTL_SKIP_THRESHOLD = 60;

    private const TWITCH_BATCH_SIZE = 100;

    public function __construct(
        private TwitchApiClient $twitch,
        private KickApiClient $kick
    ) {}

    /**
     * Poll $platform for the given $handles and write results to Redis.
     *
     * @param  string[]  $handles  Raw handles (may contain duplicates)
     */
    public function poll(string $platform, array $handles): void
    {
        $handles = array_values(array_unique($handles));
        $handles = $this->filterStaleHandles($platform, $handles);

        if (empty($handles)) {
            return;
        }

        match ($platform) {
            'twitch' => $this->pollTwitch($handles),
            'kick'   => $this->pollKick($handles),
            default  => Log::warning('streaming.unknown_platform', ['platform' => $platform]),
        };
    }

    /** @param string[] $handles */
    private function pollTwitch(array $handles): void
    {
        foreach (array_chunk($handles, self::TWITCH_BATCH_SIZE) as $batch) {
            $liveSet = array_flip($this->twitch->getLiveHandles($batch));
            foreach ($batch as $handle) {
                $this->writeStatus('twitch', $handle, isset($liveSet[$handle]));
            }
        }
    }

    /** @param string[] $handles */
    private function pollKick(array $handles): void
    {
        foreach ($handles as $handle) {
            try {
                $isLive = $this->kick->isLive($handle);
                $this->writeStatus('kick', $handle, $isLive);
            } catch (KickRateLimitException $e) {
                Log::warning('streaming.rate_limit', [
                    'platform'    => 'kick',
                    'handle'      => $handle,
                    'retry_after' => $e->retryAfter,
                ]);
                // Mark rate limited and abort remaining handles for this cycle.
                Redis::set(self::KICK_RATE_LIMITED_KEY, '1', 'EX', 300);

                return;
            }
        }
    }

    private function writeStatus(string $platform, string $handle, bool $isLive): void
    {
        Redis::set(
            self::LIVE_KEY_PREFIX."{$platform}:{$handle}",
            $isLive ? '1' : '0',
            'EX',
            self::LIVE_TTL_SECONDS
        );
    }

    /**
     * Returns handles whose Redis key is missing or has TTL <= threshold.
     * Handles with fresh entries are skipped — no API call needed.
     *
     * @param  string[]  $handles
     * @return string[]
     */
    private function filterStaleHandles(string $platform, array $handles): array
    {
        return array_values(array_filter($handles, function (string $handle) use ($platform): bool {
            $key = self::LIVE_KEY_PREFIX."{$platform}:{$handle}";
            $ttl = Redis::ttl($key);

            // -2 = key doesn't exist, -1 = no TTL, any value <= threshold = stale
            return $ttl < self::TTL_SKIP_THRESHOLD;
        }));
    }
}
```

- [ ] **Step 4: Run to verify tests pass**

```bash
composer test -- --filter=LiveStatusPollerTest
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Streaming/LiveStatusPoller.php \
        tests/Unit/Streaming/LiveStatusPollerTest.php
git commit -m "feat(streaming): add LiveStatusPoller with dedup, batching, TTL skip, rate limit abort"
```

---

### Task 9: CheckStreamingLiveStatusJob

**Files:**
- Create: `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php`
- Create: `tests/Feature/Jobs/Streaming/CheckStreamingLiveStatusJobTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Streaming/CheckStreamingLiveStatusJobTest.php`:

> **Note:** The job's DB query uses a PostgreSQL JSONB operator (`settings->>'live_check_enabled'`) that doesn't run on the SQLite test DB. These unit tests bypass the DB layer entirely and focus on the job's orchestration logic — rate limit checking, platform dispatch, and error handling. DB integration is verified manually against a staging database.

```php
<?php

/** @phpstan-ignore-all */

use App\Jobs\Streaming\CheckStreamingLiveStatusJob;
use App\Services\Streaming\LiveStatusPoller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::flushdb());

it('skips Kick entirely when rate_limited key is set in Redis', function () {
    config(['sidest.streaming_platforms' => ['twitch', 'kick']]);
    Redis::set('streaming:kick:rate_limited', '1', 'EX', 300);

    $poller = Mockery::mock(LiveStatusPoller::class);
    // Kick should NOT be dispatched
    $poller->shouldNotReceive('poll')->with('kick', Mockery::any());
    // Twitch may be dispatched (with empty handles since no DB rows in this test)
    $poller->shouldReceive('poll')->with('twitch', Mockery::any())->zeroOrMoreTimes();

    Log::shouldReceive('warning')->once()->withArgs(fn ($msg) => str_contains((string) $msg, 'rate limited'));

    $job = new CheckStreamingLiveStatusJob;
    $job->handle($poller);
});

it('logs critical and aborts when Redis is unavailable', function () {
    config(['sidest.streaming_platforms' => ['twitch', 'kick']]);

    Redis::shouldReceive('exists')
        ->once()
        ->andThrow(new \RedisException('Connection refused'));

    Log::shouldReceive('critical')->once()->with('streaming.redis_unavailable', Mockery::any());

    $poller = Mockery::mock(LiveStatusPoller::class);
    $poller->shouldNotReceive('poll');

    $job = new CheckStreamingLiveStatusJob;
    $job->handle($poller);
});

it('catches poller exceptions and logs per-platform error without crashing the job', function () {
    config(['sidest.streaming_platforms' => ['twitch']]);

    $poller = Mockery::mock(LiveStatusPoller::class);
    $poller->shouldReceive('poll')
        ->with('twitch', Mockery::any())
        ->andThrow(new \RuntimeException('Network error'));

    Log::shouldReceive('error')->once()->with('streaming.poll_error', Mockery::any());

    $job = new CheckStreamingLiveStatusJob;
    // Should not throw
    $job->handle($poller);
});

it('logs job failure via failed() callback', function () {
    Log::shouldReceive('error')->once()->with('streaming.job_failed', Mockery::any());

    $job = new CheckStreamingLiveStatusJob;
    $job->failed(new \RuntimeException('Something broke'));
});
```

- [ ] **Step 2: Run to verify they fail**

```bash
composer test -- --filter="CheckStreamingLiveStatusJobTest"
```
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement CheckStreamingLiveStatusJob**

```php
<?php

namespace App\Jobs\Streaming;

use App\Models\Core\Site\Block;
use App\Services\Streaming\LiveStatusPoller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

// Polls Twitch and Kick every 2 minutes for live status of all blocks with live_check_enabled=true.
class CheckStreamingLiveStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function handle(LiveStatusPoller $poller): void
    {
        try {
            $kickRateLimited = Redis::exists('streaming:kick:rate_limited');
        } catch (\Throwable $e) {
            Log::critical('streaming.redis_unavailable', ['message' => $e->getMessage()]);

            return;
        }

        if ($kickRateLimited) {
            Log::warning('streaming: skipping Kick — rate limited from previous cycle');
        }

        $streamingPlatforms = config('sidest.streaming_platforms', []);

        /** @var array<string, list<string>> $handlesByPlatform */
        $handlesByPlatform = array_fill_keys($streamingPlatforms, []);

        Block::query()
            ->where('block_type', 'link')
            ->whereNotNull('settings->platform')
            ->whereRaw("settings->>'live_check_enabled' = 'true'")
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->chunkById(500, function ($blocks) use (&$handlesByPlatform, $streamingPlatforms): void {
                foreach ($blocks as $block) {
                    $settings = is_array($block->settings) ? $block->settings : [];
                    $platform = $settings['platform'] ?? null;
                    $handle = $settings['handle'] ?? null;

                    if (
                        $platform
                        && $handle
                        && in_array($platform, $streamingPlatforms, true)
                    ) {
                        $handlesByPlatform[$platform][] = $handle;
                    }
                }
            });

        foreach ($handlesByPlatform as $platform => $handles) {
            if (empty($handles)) {
                continue;
            }

            if ($platform === 'kick' && $kickRateLimited) {
                continue;
            }

            try {
                $poller->poll($platform, $handles);
            } catch (\Throwable $e) {
                Log::error('streaming.poll_error', [
                    'platform' => $platform,
                    'message'  => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('streaming.job_failed', ['message' => $e->getMessage()]);
    }
}
```

- [ ] **Step 4: Run to verify tests pass**

```bash
composer test -- --filter="CheckStreamingLiveStatusJobTest"
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Streaming/CheckStreamingLiveStatusJob.php \
        tests/Unit/Streaming/CheckStreamingLiveStatusJobTest.php
git commit -m "feat(streaming): add CheckStreamingLiveStatusJob with error handling"
```

---

### Task 10: Schedule the Job

**Files:**
- Modify: `routes/console.php`

- [ ] **Step 1: Add the schedule entry**

In `routes/console.php`, append after the last `Schedule::job()` block:

```php
Schedule::job(new \App\Jobs\Streaming\CheckStreamingLiveStatusJob)
    ->everyTwoMinutes()
    ->withoutOverlapping(5)
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: check-streaming-live-status');
    });
```

- [ ] **Step 2: Verify the schedule is registered**

```bash
php artisan schedule:list
```
Expected: `CheckStreamingLiveStatusJob` appears every 2 minutes.

- [ ] **Step 3: Commit**

```bash
git add routes/console.php
git commit -m "feat(streaming): schedule CheckStreamingLiveStatusJob every 2 minutes"
```

---

### Task 11: LiveStatusInjector

**Files:**
- Create: `app/Services/Streaming/LiveStatusInjector.php`
- Create: `tests/Unit/Streaming/LiveStatusInjectorTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Streaming/LiveStatusInjectorTest.php`:

```php
<?php

/** @phpstan-ignore-all */

use App\Services\Streaming\LiveStatusInjector;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::flushdb());

it('injects is_live=true into a streaming block whose handle is live in Redis', function () {
    config(['sidest.streaming_platforms' => ['twitch', 'kick']]);
    Redis::set('streaming:live:twitch:shroud', '1', 'EX', 180);

    $blocks = [[
        'settings' => [
            'platform'           => 'twitch',
            'handle'             => 'shroud',
            'live_check_enabled' => true,
        ],
    ]];

    $injector = new LiveStatusInjector;
    $result = $injector->injectIntoBlocks($blocks);

    expect($result[0]['settings']['is_live'])->toBeTrue();
});

it('injects is_live=false when Redis key is missing', function () {
    config(['sidest.streaming_platforms' => ['twitch', 'kick']]);
    // No Redis key set

    $blocks = [[
        'settings' => [
            'platform'           => 'twitch',
            'handle'             => 'offlineuser',
            'live_check_enabled' => true,
        ],
    ]];

    $injector = new LiveStatusInjector;
    $result = $injector->injectIntoBlocks($blocks);

    expect($result[0]['settings']['is_live'])->toBeFalse();
});

it('does not add is_live to blocks where live_check_enabled is false', function () {
    config(['sidest.streaming_platforms' => ['twitch', 'kick']]);
    Redis::set('streaming:live:twitch:shroud', '1', 'EX', 180);

    $blocks = [[
        'settings' => [
            'platform'           => 'twitch',
            'handle'             => 'shroud',
            'live_check_enabled' => false,
        ],
    ]];

    $injector = new LiveStatusInjector;
    $result = $injector->injectIntoBlocks($blocks);

    expect(array_key_exists('is_live', $result[0]['settings']))->toBeFalse();
});

it('does not add is_live to non-streaming platform blocks', function () {
    config(['sidest.streaming_platforms' => ['twitch', 'kick']]);

    $blocks = [[
        'settings' => [
            'platform'           => 'instagram',
            'handle'             => 'someone',
            'live_check_enabled' => true,
        ],
    ]];

    $injector = new LiveStatusInjector;
    $result = $injector->injectIntoBlocks($blocks);

    expect(array_key_exists('is_live', $result[0]['settings']))->toBeFalse();
});

it('passes through non-link blocks unchanged', function () {
    config(['sidest.streaming_platforms' => ['twitch', 'kick']]);

    $blocks = [['block_group' => 'sections', 'type' => 'gallery']];

    $injector = new LiveStatusInjector;
    $result = $injector->injectIntoBlocks($blocks);

    expect($result)->toBe($blocks);
});

it('injects is_live into both links and blocks in the full payload', function () {
    config(['sidest.streaming_platforms' => ['twitch', 'kick']]);
    Redis::set('streaming:live:twitch:shroud', '1', 'EX', 180);

    $block = [
        'block_group' => 'links',
        'settings'    => [
            'platform'           => 'twitch',
            'handle'             => 'shroud',
            'live_check_enabled' => true,
        ],
    ];

    $payload = [
        'links'  => [$block],
        'blocks' => [$block],
        'other'  => 'unchanged',
    ];

    $injector = new LiveStatusInjector;
    $result = $injector->injectIntoPayload($payload);

    expect($result['links'][0]['settings']['is_live'])->toBeTrue();
    expect($result['blocks'][0]['settings']['is_live'])->toBeTrue();
    expect($result['other'])->toBe('unchanged');
});
```

- [ ] **Step 2: Run to verify they fail**

```bash
composer test -- --filter=LiveStatusInjectorTest
```
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement LiveStatusInjector**

```php
<?php

namespace App\Services\Streaming;

use Illuminate\Support\Facades\Redis;

/**
 * Post-processes a cached site payload to inject live status for streaming platforms.
 * Called after SiteCacheService::getPublicSitePayload() — never stored in the cache itself.
 */
class LiveStatusInjector
{
    private const LIVE_KEY_PREFIX = 'streaming:live:';

    /**
     * Injects is_live into both the `links` and `blocks` arrays in a site payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function injectIntoPayload(array $payload): array
    {
        if (isset($payload['links']) && is_array($payload['links'])) {
            $payload['links'] = $this->injectIntoBlocks($payload['links']);
        }

        if (isset($payload['blocks']) && is_array($payload['blocks'])) {
            $payload['blocks'] = $this->injectIntoBlocks($payload['blocks']);
        }

        return $payload;
    }

    /**
     * Injects is_live into each block that has live_check_enabled=true and a streaming platform.
     * Missing Redis key → is_live=false (safe default, no error).
     *
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    public function injectIntoBlocks(array $blocks): array
    {
        $streamingPlatforms = config('sidest.streaming_platforms', []);

        return array_map(function ($block) use ($streamingPlatforms) {
            if (! is_array($block)) {
                return $block;
            }

            $settings = $block['settings'] ?? [];
            if (! is_array($settings)) {
                return $block;
            }

            $platform = $settings['platform'] ?? null;
            $handle = $settings['handle'] ?? null;
            $liveCheckEnabled = (bool) ($settings['live_check_enabled'] ?? false);

            if (
                ! $liveCheckEnabled
                || ! $platform
                || ! $handle
                || ! in_array($platform, $streamingPlatforms, true)
            ) {
                return $block;
            }

            $redisKey = self::LIVE_KEY_PREFIX."{$platform}:{$handle}";
            $block['settings']['is_live'] = Redis::get($redisKey) === '1';

            return $block;
        }, $blocks);
    }
}
```

- [ ] **Step 4: Run to verify tests pass**

```bash
composer test -- --filter=LiveStatusInjectorTest
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Streaming/LiveStatusInjector.php \
        tests/Unit/Streaming/LiveStatusInjectorTest.php
git commit -m "feat(streaming): add LiveStatusInjector to post-process site payload after cache read"
```

---

### Task 12: Wire LiveStatusInjector into PublicSiteController

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/PublicSiteController.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Api/PublicSiteStreamingLiveStatusTest.php`:

> Uses the header-based `GET /public/site-by-slug` endpoint (registered at `routes/api.php:93`) with `X-Site-Subdomain` header — easier to test than the subdomain-based route which requires DNS routing.

```php
<?php

/** @phpstan-ignore-all */

use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(fn () => Redis::flushdb());

it('returns is_live=true for a live streaming link block on the public profile', function () {
    config(['sidest.streaming_platforms' => ['twitch', 'kick']]);
    Redis::set('streaming:live:twitch:shroud', '1', 'EX', 180);

    $payload = [
        'links' => [[
            'block_group' => 'links',
            'settings'    => [
                'platform'           => 'twitch',
                'handle'             => 'shroud',
                'live_check_enabled' => true,
            ],
        ]],
        'blocks' => [],
    ];

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('getPublicSitePayload')
        ->with('testsite')
        ->andReturn($payload);

    $this->app->instance(SiteCacheService::class, $cache);

    $response = $this->getJson('/public/site-by-slug', [
        'X-Site-Subdomain' => 'testsite',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.links.0.settings.is_live', true);
});

it('returns is_live=false when the handle is not in Redis', function () {
    config(['sidest.streaming_platforms' => ['twitch', 'kick']]);
    // No Redis key for this handle

    $payload = [
        'links' => [[
            'block_group' => 'links',
            'settings'    => [
                'platform'           => 'twitch',
                'handle'             => 'offlineuser',
                'live_check_enabled' => true,
            ],
        ]],
        'blocks' => [],
    ];

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('getPublicSitePayload')
        ->with('testsite')
        ->andReturn($payload);
    $this->app->instance(SiteCacheService::class, $cache);

    $response = $this->getJson('/public/site-by-slug', [
        'X-Site-Subdomain' => 'testsite',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.links.0.settings.is_live', false);
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
composer test -- --filter=PublicSiteStreamingLiveStatusTest
```
Expected: FAIL — `is_live` not present in response.

- [ ] **Step 3: Inject LiveStatusInjector into PublicSiteController**

Replace the constructor and both payload-return points in `PublicSiteController.php`:

```php
public function __construct(
    private SiteCacheService $siteCache,
    private LiveStatusInjector $liveStatus,
) {}
```

In `show()`, change:
```php
$payload = $this->siteCache->getPublicSitePayload($subdomain);
if ($payload) {
    return $this->success($payload);
}
```
To:
```php
$payload = $this->siteCache->getPublicSitePayload($subdomain);
if ($payload) {
    return $this->success($this->liveStatus->injectIntoPayload($payload));
}
```

In `showByHeader()`, make the same change for both `$payload` returns (lines 67 and 80 in the original file):
```php
// Line ~67
if ($payload) {
    return $this->success($this->liveStatus->injectIntoPayload($payload));
}
// Line ~80
if ($canonicalPayload) {
    return $this->success($this->liveStatus->injectIntoPayload($canonicalPayload));
}
```

Also add the use statement at the top of the file:
```php
use App\Services\Streaming\LiveStatusInjector;
```

- [ ] **Step 4: Run to verify tests pass**

```bash
composer test -- --filter=PublicSiteStreamingLiveStatusTest
```
Expected: PASS

- [ ] **Step 5: Run full test suite**

```bash
composer test
```
Expected: All green.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/PublicSite/PublicSiteController.php \
        tests/Feature/Api/PublicSiteStreamingLiveStatusTest.php
git commit -m "feat(streaming): wire LiveStatusInjector into PublicSiteController"
```

---

## Final Verification

- [ ] Run the full test suite one more time:

```bash
composer test
```
Expected: All green.

- [ ] Verify schedule is listed:

```bash
php artisan schedule:list | grep streaming
```
Expected: `CheckStreamingLiveStatusJob` every 2 minutes.

- [ ] Verify the public config endpoint now includes `twitch` and `kick`:

```bash
php artisan tinker --execute="dd(array_keys(config('sidest.social_platforms')))"
```
Expected: `twitch` and `kick` appear in the output.
