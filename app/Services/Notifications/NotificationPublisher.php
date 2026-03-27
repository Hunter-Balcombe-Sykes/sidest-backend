<?php

namespace App\Services\Notifications;

use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Models\Core\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationPublisher
{
    public const CATEGORIES = [
        'invites',
        'commissions',
        'payouts',
        'integrations',
        'analytics_weekly',
        'analytics_milestones',
        'profile_tasks',
        'catalog_changes',
        'brand_status',
        'subscriptions',
        'brand_links',
    ];

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
        $body  = trim($body);
        if ($title === '' || $body === '') {
            return;
        }

        $cta = $this->withDedupeKey($ctaUrl ?? '/account/overview', $dedupeKey);

        $exists = DB::table('notifications')
            ->where('professional_id', $professionalId)
            ->where('cta_url', $cta)
            ->exists();

        if ($exists) {
            return;
        }

        $now  = now();
        $type = Notification::normalizeFrontendType($frontendType);
        $key  = $retentionConfigKey ?? 'default';
        $days = config("comet.notification_retention_days.{$key}")
            ?? config('comet.notification_retention_days.default', 30);

        $notificationId = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id'                     => $notificationId,
            'professional_id'        => $professionalId,
            'type'                   => $type,
            'category'               => $category,
            'title'                  => $title,
            'body'                   => $body,
            'cta_url'                => $cta,
            'primary_action_label'   => $primaryActionLabel,
            'secondary_action_label' => $secondaryActionLabel,
            'secondary_action_url'   => $secondaryActionUrl,
            'severity'               => Notification::severityForFrontendType($type),
            'starts_at'              => $now,
            'ends_at'                => $now->copy()->addDays((int) $days),
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);

        if (config('comet.notifications.email_enabled', false)) {
            SendTransactionalNotificationEmailJob::dispatch(
                $notificationId,
                $category,
                $professionalId,
            )->onQueue('mail');
        }
    }

    public function withDedupeKey(string $url, string $dedupeKey): string
    {
        $trimmedUrl = trim($url);
        if ($trimmedUrl === '') {
            $trimmedUrl = '/account/overview';
        }

        $join = str_contains($trimmedUrl, '?') ? '&' : '?';

        return $trimmedUrl . $join . 'notif=' . $dedupeKey;
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
        $preference = DB::table('notification_email_preferences')
            ->where('professional_id', $professionalId)
            ->where('category_key', $category)
            ->value('enabled');

        if ($preference !== null) {
            return (bool) $preference;
        }

        return true; // default enabled
    }
}
