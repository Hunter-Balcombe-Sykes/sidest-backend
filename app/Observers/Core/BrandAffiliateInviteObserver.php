<?php

namespace App\Observers\Core;

use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Log;

// V2: Publishes invite notifications — "invited" to affiliate, "accepted"/"declined" to brand.
class BrandAffiliateInviteObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    // Memoize brand names within the request to avoid N+1 on bulk CSV imports.
    private static array $brandNameCache = [];

    public function __construct(private readonly NotificationPublisher $publisher) {}

    public function created(BrandAffiliateInvite $invite): void
    {
        try {
            $claimedId = trim((string) ($invite->claimed_professional_id ?? ''));
            if ($claimedId === '') {
                return;
            }

            $brandName = $this->brandName($invite->brand_professional_id);

            $this->publisher->publish(
                professionalId: $claimedId,
                frontendType: 'Invitation',
                category: 'invites',
                title: 'You have been invited',
                body: "{$brandName} has invited you to join their affiliate program.",
                dedupeKey: "invite.received.{$invite->id}",
                ctaUrl: '/account/affiliates',
                retentionConfigKey: 'invite',
            );
        } catch (\Throwable $e) {
            Log::warning('BrandAffiliateInvite created notification failed', $this->logContext(__METHOD__, [
                'invite_id' => $invite->id,
                'brand_professional_id' => $invite->brand_professional_id,
                'message' => $e->getMessage(),
            ]));
        }
    }

    public function updated(BrandAffiliateInvite $invite): void
    {
        try {
            $brandProfessionalId = trim((string) ($invite->brand_professional_id ?? ''));
            if ($brandProfessionalId === '') {
                return;
            }

            $claimedName = $this->claimedName($invite);

            // Invite accepted: accepted_at changed from null to a value
            if ($invite->isDirty('accepted_at') && $invite->accepted_at !== null) {
                $this->publisher->publish(
                    professionalId: $brandProfessionalId,
                    frontendType: 'Success',
                    category: 'invites',
                    title: 'Invite accepted',
                    body: "{$claimedName} accepted your affiliate invite.",
                    dedupeKey: "invite.accepted.{$invite->id}",
                    ctaUrl: '/account/affiliates',
                    retentionConfigKey: 'invite',
                );
            }

            // Invite declined: status changed to declined
            if ($invite->isDirty('status') && $invite->status === 'declined') {
                $this->publisher->publish(
                    professionalId: $brandProfessionalId,
                    frontendType: 'Info',
                    category: 'invites',
                    title: 'Invite declined',
                    body: 'Your affiliate invite was declined.',
                    dedupeKey: "invite.declined.{$invite->id}",
                    ctaUrl: '/account/affiliates',
                    retentionConfigKey: 'invite',
                );
            }
        } catch (\Throwable $e) {
            Log::warning('BrandAffiliateInvite updated notification failed', $this->logContext(__METHOD__, [
                'invite_id' => $invite->id,
                'brand_professional_id' => $invite->brand_professional_id,
                'message' => $e->getMessage(),
            ]));
        }
    }

    private function brandName(string $brandProfessionalId): string
    {
        if (! isset(self::$brandNameCache[$brandProfessionalId])) {
            self::$brandNameCache[$brandProfessionalId] = (string) (\Illuminate\Support\Facades\DB::table('core.professionals')
                ->where('id', $brandProfessionalId)
                ->whereNull('deleted_at')
                ->value(\Illuminate\Support\Facades\DB::raw("COALESCE(NULLIF(display_name, ''), NULLIF(handle, ''), 'Brand')")));
        }

        return self::$brandNameCache[$brandProfessionalId];
    }

    private function claimedName(BrandAffiliateInvite $invite): string
    {
        if ($invite->claimed_professional_id) {
            $name = \Illuminate\Support\Facades\DB::table('core.professionals')
                ->where('id', $invite->claimed_professional_id)
                ->whereNull('deleted_at')
                ->value(\Illuminate\Support\Facades\DB::raw("COALESCE(NULLIF(display_name, ''), NULLIF(handle, ''), NULL)"));
            if ($name) {
                return (string) $name;
            }
        }

        $firstName = trim((string) ($invite->first_name ?? ''));
        $email = trim((string) ($invite->email ?? ''));

        return $firstName !== '' ? $firstName : ($email !== '' ? $email : 'Someone');
    }
}
