<?php

namespace App\Services\Professional;

use App\Models\Core\Notifications\Notification;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Carbon;

// Inserts rows into notifications.notifications for brand-affiliate link
// removals. Called from the lifecycle service after the disconnect
// transaction commits (failure to persist a notification does not fail
// the disconnect — notifications are advisory).
class BrandPartnerLinkNotifier
{
    /** Notify the affiliate that a link has ended. */
    public function notifyAffiliateOfRemoval(
        Professional $affiliate,
        Professional $brand,
        int $voidedCents,
    ): void {
        $brandName = $this->displayName($brand);
        $severity = $voidedCents > 0 ? 'warning' : 'info';
        $body = $voidedCents > 0
            ? sprintf(
                'You are no longer linked to %s. $%s in pending commissions was voided.',
                $brandName,
                number_format($voidedCents / 100, 2),
            )
            : sprintf('You are no longer linked to %s.', $brandName);

        $this->insert($affiliate->id, [
            'title' => sprintf('Your partnership with %s has ended', $brandName),
            'body' => $body,
            'cta_url' => '/dashboard/brand-partners',
            'severity' => $severity,
        ]);
    }

    /** Notify the brand that an affiliate link has ended. */
    public function notifyBrandOfRemoval(
        Professional $brand,
        Professional $affiliate,
    ): void {
        $affiliateName = $this->displayName($affiliate);

        $this->insert($brand->id, [
            'title' => sprintf('%s has ended your partnership', $affiliateName),
            'body' => 'They are no longer linked to your brand.',
            'cta_url' => '/dashboard/affiliates',
            'severity' => 'info',
        ]);
    }

    private function insert(string $professionalId, array $attrs): void
    {
        $now = Carbon::now();

        Notification::query()->create([
            'professional_id' => $professionalId,
            'type' => 'BrandPartnerRemoved',
            'title' => $attrs['title'],
            'body' => $attrs['body'],
            'cta_url' => $attrs['cta_url'],
            'primary_action_label' => null,
            'secondary_action_label' => null,
            'secondary_action_url' => null,
            'severity' => $attrs['severity'],
            'starts_at' => $now,
            'ends_at' => null,
        ]);
    }

    private function displayName(Professional $p): string
    {
        $name = trim(implode(' ', array_filter([$p->first_name, $p->last_name])));
        if ($name !== '') {
            return $name;
        }
        return (string) ($p->display_name ?? $p->handle ?? 'Partner');
    }
}
