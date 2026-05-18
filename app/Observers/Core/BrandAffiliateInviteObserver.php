<?php

namespace App\Observers\Core;

use App\Mail\Affiliate\AffiliateInvitedMail;
use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
            $brandName = $this->brandName($invite->brand_professional_id);
            $claimedId = trim((string) ($invite->claimed_professional_id ?? ''));

            if ($claimedId !== '') {
                // The invitee already has a Partna account — in-app notification covers it.
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

                return;
            }

            // Targeted invite (has a recipient email) but no Partna account yet — send the
            // signup-prompt email so the brand doesn't have to share the link manually.
            $recipientEmail = trim((string) ($invite->email ?? ''));
            $token = trim((string) ($invite->token ?? ''));
            if ($recipientEmail === '' || $token === '') {
                // Generic open invite link (no email on the row) — nothing to send.
                return;
            }

            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
            $acceptUrl = $frontendUrl.'/account/sign-up?invite='.rawurlencode($token);
            $expiresInDays = $invite->expires_at !== null
                ? max(0, (int) now()->diffInDays($invite->expires_at, false))
                : null;

            Mail::send(new AffiliateInvitedMail(
                recipientEmail: $recipientEmail,
                recipientFirstName: is_string($invite->first_name ?? null) && trim((string) $invite->first_name) !== ''
                    ? trim((string) $invite->first_name)
                    : null,
                brandName: $brandName,
                acceptUrl: $acceptUrl,
                expiresInDays: $expiresInDays,
            ));
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
