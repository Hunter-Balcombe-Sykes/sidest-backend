<?php

namespace App\Services\Notifications;

use App\Models\Core\Notifications\Notification;
use App\Services\Cache\CacheLockService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// V2: Shared notification list + receipt-write logic for the self-service
// NotificationController and the staff-on-behalf-of StaffNotificationController.
// Both code paths must stay identical (cache key shape, receipt upsert, base
// query window) — extracting here removes the divergence risk.
class NotificationListingService
{
    private const RECEIPT_COLUMNS = ['read_at', 'dismissed_at'];

    public function __construct(private readonly CacheLockService $cache) {}

    /**
     * Cached + locked index payload for a professional.
     *
     * @return array{unread_count: int, has_more: bool, notifications: array<int, mixed>}
     */
    public function index(string $professionalId, int $limit, bool $includeDismissed): array
    {
        // 15s TTL: short enough that a server-side notification publish
        // surfaces within one poll cycle of the dashboard bell. markRead and
        // dismiss bust this key explicitly.
        return $this->cache->rememberLocked(
            $this->cacheKey($professionalId, $limit, $includeDismissed),
            15,
            fn () => $this->buildIndexPayload($professionalId, $limit, $includeDismissed),
        );
    }

    /**
     * Mark a notification as read for a professional. Bumps the index cache.
     */
    public function markRead(Notification $notification, string $professionalId): void
    {
        $this->upsertReceipt($notification->id, $professionalId, ['read_at' => now()]);
        $this->bustIndexCache($professionalId);
    }

    /**
     * Mark a notification as dismissed for a professional. Bumps the index cache.
     */
    public function dismiss(Notification $notification, string $professionalId): void
    {
        $this->upsertReceipt($notification->id, $professionalId, ['dismissed_at' => now()]);
        $this->bustIndexCache($professionalId);
    }

    /**
     * Abort 404 if the notification's time window is not currently active.
     * Callers handle ownership separately; this covers only starts_at/ends_at.
     */
    public function assertActive(Notification $notification): void
    {
        $now = now();
        if ($notification->starts_at && $now->lt($notification->starts_at)) {
            abort(404);
        }
        if ($notification->ends_at && $now->gt($notification->ends_at)) {
            abort(404);
        }
    }

    /**
     * True if the notification is either global (professional_id null) or
     * targeted to this professional. Used by staff endpoints, which can't
     * lean on NotificationPolicy because the actor is staff, not the pro.
     */
    public function visibleTo(Notification $notification, string $professionalId): bool
    {
        if ($notification->professional_id === null) {
            return true;
        }

        return (string) $notification->professional_id === $professionalId;
    }

    /**
     * @return array{unread_count: int, has_more: bool, notifications: array<int, mixed>}
     */
    private function buildIndexPayload(string $professionalId, int $limit, bool $includeDismissed): array
    {
        $now = now();
        $base = $this->baseQuery($professionalId, $now);

        $listQuery = clone $base;
        if (! $includeDismissed) {
            $listQuery->whereNull('r.dismissed_at');
        }

        $rows = $listQuery
            ->orderByDesc('n.created_at')
            ->limit($limit + 1)
            ->get([
                'n.id',
                'n.professional_id',
                'n.type',
                'n.title',
                'n.body',
                'n.cta_url',
                'n.primary_action_label',
                'n.secondary_action_label',
                'n.secondary_action_url',
                'n.severity',
                'n.starts_at',
                'n.ends_at',
                'n.created_at',
                'r.read_at',
                'r.dismissed_at',
            ]);

        $rows = $rows->map(function ($row) {
            $row->type = Notification::normalizeFrontendType(
                is_string($row->type ?? null) ? $row->type : null,
                is_string($row->severity ?? null) ? $row->severity : null,
            );
            $row->severity = Notification::severityForFrontendType($row->type);

            return $row;
        });

        $hasMore = $rows->count() > $limit;
        if ($hasMore) {
            $rows = $rows->take($limit);
        }

        $unreadCount = (clone $base)
            ->whereNull('r.read_at')
            ->whereNull('r.dismissed_at')
            ->count();

        return [
            'unread_count' => $unreadCount,
            'has_more' => $hasMore,
            // Re-index to a plain array so JSON encodes as `[...]` not `{0:...}`
            // after the trailing limit+1 row was sliced off above.
            'notifications' => $rows->values()->all(),
        ];
    }

    private function baseQuery(string $professionalId, $now)
    {
        return DB::table('notifications.notifications as n')
            ->leftJoin('notifications.notification_receipts as r', function ($join) use ($professionalId) {
                $join->on('r.notification_id', '=', 'n.id')
                    ->where('r.professional_id', '=', $professionalId);
            })
            ->where(function ($q) use ($professionalId) {
                $q->whereNull('n.professional_id')
                    ->orWhere('n.professional_id', '=', $professionalId);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('n.starts_at')->orWhere('n.starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('n.ends_at')->orWhere('n.ends_at', '>=', $now);
            });
    }

    private function cacheKey(string $professionalId, int $limit, bool $includeDismissed): string
    {
        return "pro:{$professionalId}:notifications:".$limit.':'.($includeDismissed ? 'dismissed' : 'live');
    }

    /**
     * Bust every variant of the notifications index cache for a professional.
     * Iterates the small known set of (limit, include_dismissed) keys the
     * frontend uses — narrower than a tagged flush and cheap on Redis.
     */
    private function bustIndexCache(string $professionalId): void
    {
        // Pinned to redis in non-test envs — a file/array driver fallback in
        // prod would only clear the local worker's copy, leaving other workers
        // serving the stale unread-count until the 15s TTL expires naturally.
        $store = app()->environment('testing') ? Cache::store() : Cache::store('redis');
        foreach ([50, 100, 200] as $limit) {
            foreach ([false, true] as $includeDismissed) {
                $key = $this->cacheKey($professionalId, $limit, $includeDismissed);
                $store->forget($key);
                $store->forget($key.':stale');
            }
        }
    }

    private function upsertReceipt(string $notificationId, string $professionalId, array $set): void
    {
        // Whitelist — only read_at / dismissed_at can be set, no other columns.
        $set = array_intersect_key($set, array_flip(self::RECEIPT_COLUMNS));

        DB::table('notifications.notification_receipts')->upsert(
            [array_merge([
                'id' => (string) Str::uuid(),
                'notification_id' => $notificationId,
                'professional_id' => $professionalId,
                'created_at' => now(),
                'updated_at' => now(),
            ], $set)],
            ['notification_id', 'professional_id'],  // unique-by columns
            [...array_keys($set), 'updated_at'],     // columns to overwrite on conflict
        );
    }
}
