<?php

namespace App\Http\Controllers\Api\Professional\Notifications;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use Illuminate\Support\Str;

class NotificationController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    /**
     * GET /me/notifications
     * Returns notifications targeted to the current pro + broadcasts.
     * Read/dismiss state is stored per-user in core.notification_receipts.
     */
    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));

        $includeDismissed = filter_var($request->query('include_dismissed', false), FILTER_VALIDATE_BOOLEAN);
        $now = now();

        $base = $this->baseQuery($pro->id, $now);

        $listQuery = clone $base;
        if (!$includeDismissed) {
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

        return $this->success([
            'unread_count' => $unreadCount,
            'has_more' => $hasMore,
            'notifications' => $rows,
        ]);
    }

    private const RECEIPT_COLUMNS = ['read_at', 'dismissed_at'];

    private function upsertReceipt(string $notificationId, string $professionalId, array $set): void
    {
        $set = array_intersect_key($set, array_flip(self::RECEIPT_COLUMNS));
        $id = (string) Str::uuid();

        $cols = array_keys($set);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $updates = implode(', ', array_map(fn($c) => "{$c} = EXCLUDED.{$c}", $cols));

        $sql = "
        INSERT INTO core.notification_receipts (id, notification_id, professional_id, ".implode(', ', $cols).", created_at, updated_at)
        VALUES (?, ?, ?, {$placeholders}, NOW(), NOW())
        ON CONFLICT (notification_id, professional_id)
        DO UPDATE SET {$updates}, updated_at = NOW()
        ";

        DB::statement($sql, array_merge([$id, $notificationId, $professionalId], array_values($set)));
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $this->assertVisibleToPro($notification, $pro->id);

        $this->upsertReceipt($notification->id, $pro->id, ['read_at' => now()]);

        return $this->success(['ok' => true]);
    }

    public function dismiss(Request $request, Notification $notification): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $this->assertVisibleToPro($notification, $pro->id);

        $this->upsertReceipt($notification->id, $pro->id, ['dismissed_at' => now()]);

        return $this->success(['ok' => true]);
    }


    private function baseQuery(string $professionalId, $now)
    {
        return DB::table('notifications as n')
            ->leftJoin('notification_receipts as r', function ($join) use ($professionalId) {
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

    private function assertVisibleToPro(Notification $notification, string $professionalId): void
    {
        if ($notification->professional_id !== null && $notification->professional_id !== $professionalId) {
            abort(404);
        }

        $now = now();
        if ($notification->starts_at && $now->lt($notification->starts_at)) {
            abort(404);
        }
        if ($notification->ends_at && $now->gt($notification->ends_at)) {
            abort(404);
        }
    }
}
