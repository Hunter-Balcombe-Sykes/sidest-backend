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
        return array_keys((array) config('sidest.notifications.mailables', []));
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
        $days = config("sidest.notification_retention_days.{$retentionKey}")
            ?? config('sidest.notification_retention_days.default', 30);

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
        if ($inserted > 0 && config('sidest.notifications.email_enabled', false)) {
            SendTransactionalNotificationEmailJob::dispatch(
                $notificationId,
                $category,
                $professionalId,
            )->onQueue('mail');
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
