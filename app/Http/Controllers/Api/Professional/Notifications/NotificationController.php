<?php

namespace App\Http\Controllers\Api\Professional\Notifications;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Models\Core\Notifications\Notification;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// V2: In-app notification listing, mark-as-read, and dismiss for the authenticated professional.
class NotificationController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    /**
     * GET /me/notifications
     * Returns notifications targeted to the current pro + broadcasts.
     * Read/dismiss state is stored per-user in notifications.notification_receipts.
     */
    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));

        $includeDismissed = filter_var($request->query('include_dismissed', false), FILTER_VALIDATE_BOOLEAN);

        // 15s TTL: short enough that a server-side notification publish
        // (NotificationPublisher::publish, BrandPartnerLinkNotifier, etc — none
        // of which fire Eloquent observers because they write via DB::table())
        // surfaces within one poll cycle of the dashboard bell. markRead and
        // dismiss bust this key explicitly. Cache key segments by limit +
        // include_dismissed so frontend variants don't poison each other.
        $payload = app(CacheLockService::class)->rememberLocked(
            $this->cacheKey($pro->id, $limit, $includeDismissed),
            15,
            fn () => $this->buildIndexPayload($pro->id, $limit, $includeDismissed),
        );

        return $this->success($payload);
    }

    /**
     * Build the raw index() payload (used by both the cached fast-path and the
     * cache-fill closure). Two queries: one paginated list with limit+1 to
     * detect `has_more`, plus one COUNT for the unread badge.
     *
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

    private function cacheKey(string $professionalId, int $limit, bool $includeDismissed): string
    {
        return "pro:{$professionalId}:notifications:".$limit.':'.($includeDismissed ? 'dismissed' : 'live');
    }

    /**
     * Bust every variant of the notifications index cache for a professional.
     * Called from markRead / dismiss after the receipt write so the next poll
     * sees the new state immediately rather than waiting up to 15s for TTL.
     * Iterates the small known set of (limit, include_dismissed) keys the
     * frontend uses — narrower than a tagged flush and cheap on Redis.
     */
    private function bustIndexCache(string $professionalId): void
    {
        // Common limit values observed in the frontend; if a new variant is
        // ever added the 15s TTL will absorb the lag until next poll.
        foreach ([50, 100, 200] as $limit) {
            foreach ([false, true] as $includeDismissed) {
                $key = $this->cacheKey($professionalId, $limit, $includeDismissed);
                Cache::forget($key);
                Cache::forget($key.':stale');
            }
        }
    }

    private const RECEIPT_COLUMNS = ['read_at', 'dismissed_at'];

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

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $this->authorizeForUser($pro, 'view', $notification);
        $this->assertNotificationActive($notification);

        $this->upsertReceipt($notification->id, $pro->id, ['read_at' => now()]);
        $this->bustIndexCache($pro->id);

        return $this->success(['ok' => true]);
    }

    public function dismiss(Request $request, Notification $notification): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $this->authorizeForUser($pro, 'view', $notification);
        $this->assertNotificationActive($notification);

        $this->upsertReceipt($notification->id, $pro->id, ['dismissed_at' => now()]);
        $this->bustIndexCache($pro->id);

        return $this->success(['ok' => true]);
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

    /**
     * Abort 404 if the notification's time window is not currently active.
     * Ownership is handled by the policy; this covers starts_at/ends_at business logic.
     */
    private function assertNotificationActive(Notification $notification): void
    {
        $now = now();
        if ($notification->starts_at && $now->lt($notification->starts_at)) {
            abort(404);
        }
        if ($notification->ends_at && $now->gt($notification->ends_at)) {
            abort(404);
        }
    }
}
