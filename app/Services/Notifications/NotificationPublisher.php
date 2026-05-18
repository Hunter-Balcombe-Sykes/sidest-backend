<?php

namespace App\Services\Notifications;

use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Models\Core\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// V2: Core notification engine. Publishes with atomic dedup via dedupe_key column,
// optional email dispatch, retention policies, and per-professional category overrides.
class NotificationPublisher
{
    /**
     * Valid category keys — derived from the single source of truth in
     * config/sidest.php. FormRequests and controllers should prefer calling
     * this method directly over importing a constant.
     *
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return array_keys((array) config('partna.notifications.mailables', []));
    }

    public function publish(
        string $professionalId,
        string $frontendType,
        string $category,
        string $title,
        string $body,
        string $dedupeKey,
        ?string $ctaUrl = null,
        ?string $primaryActionLabel = 'View',
        ?string $secondaryActionLabel = 'Dismiss',
        ?string $secondaryActionUrl = null,
        ?string $retentionConfigKey = null,
    ): void {
        $professionalId = trim($professionalId);
        if ($professionalId === '') {
            return;
        }

        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            return;
        }

        $dedupeKey = trim($dedupeKey);
        if ($dedupeKey === '') {
            // Require a non-empty dedupe key — callers should always provide one.
            return;
        }

        $now = now();
        $type = Notification::normalizeFrontendType($frontendType);
        $retentionKey = $retentionConfigKey ?? 'default';
        $days = config("partna.notification_retention_days.{$retentionKey}")
            ?? config('partna.notification_retention_days.default', 30);

        $notificationId = (string) Str::uuid();

        // Atomic upsert: ON CONFLICT on (professional_id, dedupe_key) DO NOTHING.
        // If a notification with this dedupe_key already exists for this pro,
        // this is a no-op — no duplicate row, no race window.
        $inserted = DB::table('notifications.notifications')->insertOrIgnore([
            'id' => $notificationId,
            'professional_id' => $professionalId,
            'type' => $type,
            'category' => $category,
            'title' => $title,
            'body' => $body,
            'cta_url' => $ctaUrl ?? '/account/overview',
            'primary_action_label' => $primaryActionLabel,
            'secondary_action_label' => $secondaryActionLabel,
            'secondary_action_url' => $secondaryActionUrl,
            'severity' => Notification::severityForFrontendType($type),
            'starts_at' => $now,
            'ends_at' => $now->copy()->addDays((int) $days),
            'dedupe_key' => $dedupeKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Only dispatch the email job for genuinely-new rows. insertOrIgnore()
        // returns the number of rows actually inserted (0 on conflict).
        if ($inserted > 0 && config('partna.notifications.email_enabled', false)) {
            SendTransactionalNotificationEmailJob::dispatch(
                $notificationId,
                $category,
                $professionalId,
            )->onQueue('mail');
        }
    }

    /**
     * Bulk variant of {@see publish()}. One insertOrIgnore + one select-back to
     * identify genuinely-new rows, then a per-row email dispatch only for those.
     * Use for fan-out (e.g. brand-affiliate invite batches) — single-shot
     * callers should keep using publish().
     *
     * Each item must supply the same keys as publish()'s named args (minus the
     * email-dispatch trigger). Items with empty professional_id, title, body,
     * or dedupe_key are silently skipped.
     *
     * @param  array<int, array{
     *     professionalId: string,
     *     frontendType: string,
     *     category: string,
     *     title: string,
     *     body: string,
     *     dedupeKey: string,
     *     ctaUrl?: string|null,
     *     primaryActionLabel?: string|null,
     *     secondaryActionLabel?: string|null,
     *     secondaryActionUrl?: string|null,
     *     retentionConfigKey?: string|null,
     * }>  $items
     */
    public function publishMany(array $items): void
    {
        if ($items === []) {
            return;
        }

        $now = now();
        $rows = [];
        $idToCategoryAndPro = [];

        foreach ($items as $item) {
            $professionalId = trim((string) ($item['professionalId'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            $body = trim((string) ($item['body'] ?? ''));
            $dedupeKey = trim((string) ($item['dedupeKey'] ?? ''));
            if ($professionalId === '' || $title === '' || $body === '' || $dedupeKey === '') {
                continue;
            }

            $type = Notification::normalizeFrontendType((string) ($item['frontendType'] ?? ''));
            $category = (string) ($item['category'] ?? '');
            $retentionKey = $item['retentionConfigKey'] ?? 'default';
            $days = config("partna.notification_retention_days.{$retentionKey}")
                ?? config('partna.notification_retention_days.default', 30);
            $notificationId = (string) Str::uuid();

            $rows[] = [
                'id' => $notificationId,
                'professional_id' => $professionalId,
                'type' => $type,
                'category' => $category,
                'title' => $title,
                'body' => $body,
                'cta_url' => $item['ctaUrl'] ?? '/account/overview',
                'primary_action_label' => $item['primaryActionLabel'] ?? 'View',
                'secondary_action_label' => $item['secondaryActionLabel'] ?? 'Dismiss',
                'secondary_action_url' => $item['secondaryActionUrl'] ?? null,
                'severity' => Notification::severityForFrontendType($type),
                'starts_at' => $now,
                'ends_at' => $now->copy()->addDays((int) $days),
                'dedupe_key' => $dedupeKey,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $idToCategoryAndPro[$notificationId] = [$category, $professionalId];
        }

        if ($rows === []) {
            return;
        }

        DB::table('notifications.notifications')->insertOrIgnore($rows);

        if (! config('partna.notifications.email_enabled', false)) {
            return;
        }

        // Select back the IDs that actually landed. Conflicting rows (same
        // professional_id + dedupe_key already present) were ignored, so their
        // UUIDs won't be in the table.
        $insertedIds = DB::table('notifications.notifications')
            ->whereIn('id', array_keys($idToCategoryAndPro))
            ->pluck('id')
            ->all();

        foreach ($insertedIds as $id) {
            [$category, $professionalId] = $idToCategoryAndPro[$id];
            SendTransactionalNotificationEmailJob::dispatch($id, $category, $professionalId)
                ->onQueue('mail');
        }
    }

    public static function resolveEmailEnabled(string $professionalId, string $category): bool
    {
        // Per-professional policy
        $perProMode = DB::table('core.notification_email_policies')
            ->where('professional_id', $professionalId)
            ->where('category_key', $category)
            ->value('mode');

        if ($perProMode === 'force_on') {
            return true;
        }
        if ($perProMode === 'force_off') {
            return false;
        }

        // Global policy
        $globalMode = DB::table('core.notification_email_policies')
            ->whereNull('professional_id')
            ->where('category_key', $category)
            ->value('mode');

        if ($globalMode === 'force_on') {
            return true;
        }
        if ($globalMode === 'force_off') {
            return false;
        }

        // Professional preference
        $preference = DB::table('notifications.notification_email_preferences')
            ->where('professional_id', $professionalId)
            ->where('category_key', $category)
            ->value('enabled');

        if ($preference !== null) {
            return (bool) $preference;
        }

        return true; // default enabled
    }
}
