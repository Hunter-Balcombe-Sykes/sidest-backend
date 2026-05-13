<?php

namespace App\Services\Professional;

use App\Jobs\DeleteMediaArtifactsJob;
use App\Mail\Notifications\AccountDeletionCancelledMail;
use App\Mail\Notifications\AccountDeletionRequestedMail;
use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalDeletionAuditEntry;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// V2: All account-deletion business logic. Called from
// ProfessionalAccountDeletionController for request/confirm/cancel flows, and
// from PurgeSoftDeleted command for hard-delete after grace period.
class AccountDeletionService
{
    /**
     * Initiate a deletion request. Checks preconditions, stores hashed token,
     * sends confirmation email. Rolls back token storage if mail send fails.
     *
     * @return array{success: bool, code: int, error?: string, reasons?: array<string>}
     */
    public function request(Professional $professional, Request $request): array
    {
        $obligations = $this->checkObligations($professional);
        if (! empty($obligations)) {
            return [
                'success' => false,
                'code' => 422,
                'error' => 'Outstanding obligations must be settled before deletion.',
                'reasons' => $obligations,
            ];
        }

        $rawToken = Str::random(64);
        $tokenHash = hash('sha256', $rawToken);

        $professional->update([
            'deletion_token_hash' => $tokenHash,
            'deletion_requested_at' => now(),
        ]);

        $confirmationUrl = rtrim((string) config('app.frontend_url'), '/')
            .'/account/deletion/confirm?token='.$rawToken;

        try {
            Mail::to($professional->primary_email)->send(
                new AccountDeletionRequestedMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                    confirmationUrl: $confirmationUrl,
                )
            );
        } catch (\Throwable $e) {
            // Mail failed — roll back token so user can retry cleanly.
            $professional->update([
                'deletion_token_hash' => null,
                'deletion_requested_at' => null,
            ]);

            Log::error('Account deletion request mail failed', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'code' => 503,
                'error' => 'Failed to send confirmation email. Please try again.',
            ];
        }

        $this->logAuditEvent($professional, ProfessionalDeletionAuditEntry::EVENT_REQUESTED, $request);

        return ['success' => true, 'code' => 200];
    }

    /**
     * Confirm deletion via token. Snapshots previous status, flips to
     * pending_deletion, deletes integration credentials, schedules Stripe
     * cancel-at-period-end, sends scheduled mail.
     *
     * @return array{success: bool, code: int, error?: string, deletes_at?: string}
     */
    public function confirm(Professional $professional, string $rawToken, Request $request): array
    {
        // No deletion request on file?
        if (! $professional->deletion_token_hash || ! $professional->deletion_requested_at) {
            return ['success' => false, 'code' => 404, 'error' => 'No deletion request found.'];
        }

        // Token expired?
        $requestedAt = $professional->deletion_requested_at instanceof \DateTimeInterface
            ? Carbon::instance($professional->deletion_requested_at)
            : Carbon::parse((string) $professional->deletion_requested_at);

        if ($requestedAt->lt(now()->subHours(24))) {
            $professional->update([
                'deletion_token_hash' => null,
                'deletion_requested_at' => null,
            ]);

            return ['success' => false, 'code' => 410, 'error' => 'Confirmation token has expired.'];
        }

        // Token mismatch? Timing-safe comparison.
        if (! hash_equals((string) $professional->deletion_token_hash, hash('sha256', $rawToken))) {
            return ['success' => false, 'code' => 404, 'error' => 'Invalid token.'];
        }

        $deletesAt = $this->executeConfirmation($professional);

        $this->logAuditEvent($professional, ProfessionalDeletionAuditEntry::EVENT_CONFIRMED, $request);

        return [
            'success' => true,
            'code' => 200,
            'deletes_at' => $deletesAt->toIso8601String(),
        ];
    }

    /**
     * Apply the confirmed deletion: snapshot status, flip to pending_deletion,
     * revoke integration credentials, schedule Stripe cancel-at-period-end,
     * send scheduled email. Shared by self-service confirm() and admin
     * adminInitiate(). Returns the deletes_at timestamp.
     */
    private function executeConfirmation(Professional $professional): Carbon
    {
        $retentionDays = (int) config('partna.soft_delete_retention_days', 30);
        $deletesAt = now()->addDays($retentionDays);
        $previousStatus = (string) ($professional->status ?? 'active');

        DB::transaction(function () use ($professional, $previousStatus) {
            $professional->update([
                'deletion_previous_status' => $previousStatus,
                'status' => 'pending_deletion',
                'deletion_confirmed_at' => now(),
                'deletion_token_hash' => null,
            ]);

            // Defense-in-depth: revoke integration credentials immediately
            // rather than leaving them in the DB for the 30-day grace period.
            ProfessionalIntegration::query()
                ->where('professional_id', $professional->id)
                ->delete();
        });

        $this->cancelStripeAtPeriodEnd($professional);

        $cancelUrl = rtrim((string) config('app.frontend_url'), '/').'/account/deletion/cancel';

        try {
            Mail::to($professional->primary_email)->send(
                new AccountDeletionScheduledMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                    deletesAt: $deletesAt->toDayDateTimeString(),
                    cancelUrl: $cancelUrl,
                )
            );
        } catch (\Throwable $e) {
            Log::error('Account deletion scheduled mail failed', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
            // Do not fail the confirmation — the deletion is more important
            // than the mail. Cancel flow remains available via logged-in session.
        }

        return $deletesAt;
    }

    /**
     * Admin-initiated deletion. Skips the email-token confirm step and goes
     * straight to scheduling the 30-day grace period. Used when a professional
     * emails support requesting erasure (e.g., GDPR Article 17 request).
     *
     * @param  Professional  $professional  The user being deleted.
     * @param  string  $staffActorId  PartnaStaff.id of the admin invoking this.
     * @param  string  $staffActorHandle  Snapshot of staff name (or email) for audit.
     * @param  string  $reason  GDPR reason / support ticket reference (10–500 chars).
     * @param  bool  $overrideObligations  If true, proceed despite unpaid balance / pending payouts.
     * @return array{success: bool, code: int, error?: string, reasons?: array<string>, deletes_at?: string}
     */
    public function adminInitiate(
        Professional $professional,
        string $staffActorId,
        string $staffActorHandle,
        string $reason,
        bool $overrideObligations,
        Request $request,
    ): array {
        if ($professional->status === 'pending_deletion') {
            return ['success' => false, 'code' => 409, 'error' => 'Deletion already in progress.'];
        }

        $obligations = $this->checkObligations($professional);

        if (! empty($obligations) && ! $overrideObligations) {
            return [
                'success' => false,
                'code' => 422,
                'error' => 'Outstanding obligations must be settled or explicitly overridden.',
                'reasons' => $obligations,
            ];
        }

        $deletesAt = $this->executeConfirmation($professional);

        $metadata = ! empty($obligations)
            ? ['obligations_overridden' => $obligations]
            : [];

        $this->logAuditEvent(
            $professional,
            ProfessionalDeletionAuditEntry::EVENT_ADMIN_INITIATED,
            $request,
            $metadata,
            ProfessionalDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN,
            $staffActorId,
            $staffActorHandle,
            $reason,
        );

        return [
            'success' => true,
            'code' => 200,
            'deletes_at' => $deletesAt->toIso8601String(),
        ];
    }

    /**
     * Admin-initiated cancel during grace period. Same lifecycle as self-service
     * cancel() but the audit row records which staff member triggered the cancel.
     *
     * @return array{success: bool, code: int, error?: string}
     */
    public function adminCancel(
        Professional $professional,
        string $staffActorId,
        string $staffActorHandle,
        ?string $reason,
        Request $request,
    ): array {
        if ($professional->status !== 'pending_deletion') {
            return ['success' => false, 'code' => 409, 'error' => 'No pending deletion to cancel.'];
        }

        $previousStatus = $professional->deletion_previous_status;
        if (! is_string($previousStatus) || $previousStatus === '') {
            $previousStatus = 'active';
        }

        DB::transaction(function () use ($professional, $previousStatus) {
            $professional->update([
                'status' => $previousStatus,
                'deletion_requested_at' => null,
                'deletion_confirmed_at' => null,
                'deletion_previous_status' => null,
                'deletion_token_hash' => null,
            ]);
        });

        $this->resumeStripeSubscription($professional);

        try {
            Mail::to($professional->primary_email)->send(
                new AccountDeletionCancelledMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                )
            );
        } catch (\Throwable $e) {
            Log::error('Account deletion cancelled mail failed', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logAuditEvent(
            $professional,
            ProfessionalDeletionAuditEntry::EVENT_ADMIN_CANCELLED,
            $request,
            [],
            ProfessionalDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN,
            $staffActorId,
            $staffActorHandle,
            $reason,
        );

        return ['success' => true, 'code' => 200];
    }

    /**
     * Cancel a pending deletion during the grace period. Restores previous
     * status, clears deletion timestamps, attempts to reverse Stripe
     * cancel-at-period-end, sends cancellation mail.
     *
     * @return array{success: bool, code: int}
     */
    public function cancel(Professional $professional, Request $request): array
    {
        $previousStatus = $professional->deletion_previous_status;
        if (! is_string($previousStatus) || $previousStatus === '') {
            $previousStatus = 'active';
        }

        DB::transaction(function () use ($professional, $previousStatus) {
            $professional->update([
                'status' => $previousStatus,
                'deletion_requested_at' => null,
                'deletion_confirmed_at' => null,
                'deletion_previous_status' => null,
                'deletion_token_hash' => null,
            ]);
        });

        $this->resumeStripeSubscription($professional);

        try {
            Mail::to($professional->primary_email)->send(
                new AccountDeletionCancelledMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                )
            );
        } catch (\Throwable $e) {
            Log::error('Account deletion cancelled mail failed', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logAuditEvent($professional, ProfessionalDeletionAuditEntry::EVENT_CANCELLED, $request);

        return ['success' => true, 'code' => 200];
    }

    /**
     * Hard-delete a professional whose grace period has elapsed. Called by
     * PurgeSoftDeleted command. Returns false on any failure so the caller
     * can retry on the next daily run.
     */
    public function purge(Professional $professional): bool
    {
        $handleSnapshot = (string) ($professional->handle ?? '');
        $emailSnapshot = (string) ($professional->primary_email ?? '');
        $authUserId = (string) ($professional->auth_user_id ?? '');

        // Step 1: delete Supabase auth user. If this fails, do NOT hard-delete
        // the DB row — we'd end up with an orphaned auth user and no way to retry.
        if ($authUserId !== '' && ! $this->deleteSupabaseAuthUser($authUserId)) {
            $this->logAuditEvent(
                $professional,
                ProfessionalDeletionAuditEntry::EVENT_PURGE_FAILED,
                null,
                ['reason' => 'supabase_deletion_failed'],
                ProfessionalDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
            );

            return false;
        }

        // Step 2: clean up R2 artifacts before the DB cascade deletes the rows.
        // forceDelete() cascades to site_media, but DB cascades do not touch R2 storage.
        $this->purgeMediaArtifacts($professional);

        // Step 3: bust the public site cache (15-min TTL) so a just-purged site
        // stops serving stale payloads to public requests the instant we delete.
        // invalidateSite() handles the main subdomain + all aliases in one call.
        $site = Site::query()->where('professional_id', $professional->id)->first();
        if ($site) {
            try {
                app(SiteCacheService::class)->invalidateSite($site);
            } catch (\Throwable $e) {
                Log::warning('Site cache invalidation failed during account purge', [
                    'professional_id' => $professional->id,
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Step 4: hard-delete professional row. DB handles cascades (42 FKs CASCADE,
        // 3 previously-RESTRICT FKs now SET NULL). forceDelete triggers model events.
        try {
            $professional->forceDelete();
        } catch (\Throwable $e) {
            Log::error('Professional forceDelete failed during purge', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
            $this->logAuditEvent(
                $professional,
                ProfessionalDeletionAuditEntry::EVENT_PURGE_FAILED,
                null,
                ['reason' => 'force_delete_failed', 'error' => $e->getMessage()],
                ProfessionalDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
            );

            return false;
        }

        // Direct create (not logAuditEvent) — the professional row was just
        // force-deleted, so professional_id must be NULL to satisfy the FK.
        // Snapshots taken at the top of purge() preserve identity for forensics.
        ProfessionalDeletionAuditEntry::create([
            'professional_id' => null,
            'professional_handle_snapshot' => $handleSnapshot,
            'professional_email_snapshot' => $emailSnapshot,
            'event' => ProfessionalDeletionAuditEntry::EVENT_PURGED,
            'actor_type' => ProfessionalDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
        ]);

        return true;
    }

    /**
     * Check for unsettled financial obligations. Returns reason codes.
     *
     * @return array<string>
     */
    private function checkObligations(Professional $professional): array
    {
        $reasons = [];

        $hasPendingPayouts = DB::connection('pgsql')
            ->table('commerce.commission_payouts')
            ->where(function ($q) use ($professional) {
                $q->where('brand_professional_id', $professional->id)
                    ->orWhere('affiliate_professional_id', $professional->id);
            })
            ->where('status', '!=', 'paid')
            ->exists();

        if ($hasPendingPayouts) {
            $reasons[] = 'pending_payouts';
        }

        return $reasons;
    }

    /**
     * Fetch the billing.subscriptions row with a Stripe subscription ID for this
     * professional. Returns null when no such row exists.
     */
    private function findStripeSubscription(Professional $professional): ?object
    {
        return DB::connection('pgsql')
            ->table('billing.subscriptions')
            ->where('professional_id', $professional->id)
            ->whereNotNull('stripe_subscription_id')
            ->first();
    }

    /**
     * Schedule Stripe subscription to cancel at the end of the current billing
     * period. Best effort — log and continue on failure.
     */
    private function cancelStripeAtPeriodEnd(Professional $professional): void
    {
        try {
            $subscription = $this->findStripeSubscription($professional);

            if (! $subscription || empty($subscription->stripe_subscription_id)) {
                return;
            }

            if (! config('services.stripe.secret_key')) {
                return; // Stripe not configured (e.g. test env) — skip.
            }

            $billing = app(StripeBillingService::class);
            $billing->cancelSubscriptionAtPeriodEnd($subscription->stripe_subscription_id);
        } catch (\Throwable $e) {
            Log::error('Stripe cancel-at-period-end failed during deletion confirm', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reverse Stripe subscription cancel-at-period-end. Best effort — if the
     * billing period already ended, the subscription is gone and we log-and-continue.
     */
    private function resumeStripeSubscription(Professional $professional): void
    {
        try {
            $subscription = $this->findStripeSubscription($professional);

            if (! $subscription || empty($subscription->stripe_subscription_id)) {
                return;
            }

            if (! config('services.stripe.secret_key')) {
                return;
            }

            $billing = app(StripeBillingService::class);
            $billing->resumeSubscription($subscription->stripe_subscription_id);
        } catch (\Throwable $e) {
            Log::error('Stripe subscription resume failed during deletion cancel', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Call Supabase Admin API to delete an auth user. 404 is treated as success
     * (user already deleted). Any other non-2xx response is a failure.
     */
    private function deleteSupabaseAuthUser(string $authUserId): bool
    {
        $baseUrl = rtrim((string) config('supabase.url'), '/');
        $serviceKey = (string) config('supabase.service_role_key');

        if ($baseUrl === '' || $serviceKey === '') {
            Log::error('Supabase credentials not configured; cannot delete auth user', [
                'auth_user_id' => $authUserId,
            ]);

            return false;
        }

        $response = Http::withHeaders([
            'apikey' => $serviceKey,
            'Authorization' => 'Bearer '.$serviceKey,
        ])->delete("{$baseUrl}/auth/v1/admin/users/{$authUserId}");

        if ($response->status() === 404) {
            return true;
        }

        if (! $response->successful()) {
            Log::error('Supabase auth user deletion failed', [
                'auth_user_id' => $authUserId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Enumerate all site media for this professional and clean up R2 artifacts.
     * Videos are dispatched async (many HLS segments). Images and documents are
     * deleted synchronously (single file per record). Failures are logged and
     * skipped — a storage error must never block the DB deletion.
     */
    private function purgeMediaArtifacts(Professional $professional): void
    {
        $site = Site::query()->where('professional_id', $professional->id)->first();

        if (! $site) {
            return;
        }

        $mediaItems = SiteMedia::query()
            ->withTrashed()
            ->where('site_id', $site->id)
            ->get();

        foreach ($mediaItems as $media) {
            try {
                match ($media->media_type) {
                    SiteMedia::MEDIA_TYPE_VIDEO => $this->purgeVideoArtifacts($media),
                    SiteMedia::MEDIA_TYPE_DOCUMENT => $this->purgeDocumentArtifact($media),
                    default => $this->purgeImageArtifacts($media),
                };
            } catch (\Throwable $e) {
                Log::warning('R2 artifact cleanup failed for media item during account purge', [
                    'professional_id' => $professional->id,
                    'media_id' => $media->id,
                    'media_type' => $media->media_type,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function purgeVideoArtifacts(SiteMedia $media): void
    {
        if (! $media->path) {
            return;
        }

        DeleteMediaArtifactsJob::dispatch($media->id, $media->path, (string) $media->pool);
    }

    private function purgeImageArtifacts(SiteMedia $media): void
    {
        app(ImageVariantService::class)->deleteVariants($media->id, $media->path ?: null);
    }

    private function purgeDocumentArtifact(SiteMedia $media): void
    {
        if (! $media->path) {
            return;
        }

        $disk = Storage::disk((string) config('partna.media_disk'));
        if ($disk->exists($media->path)) {
            $disk->delete($media->path);
        }
    }

    /**
     * Append an audit row. Captures handle/email snapshots so the row survives
     * the professional's eventual hard delete. Actor parameters identify who
     * triggered this event — the professional themselves (self-service),
     * a staff admin (support-initiated), or the system (daily purge command).
     */
    public function logAuditEvent(
        Professional $professional,
        string $event,
        ?Request $request = null,
        array $metadata = [],
        string $actorType = ProfessionalDeletionAuditEntry::ACTOR_TYPE_PROFESSIONAL,
        ?string $actorId = null,
        ?string $actorHandle = null,
        ?string $reason = null,
    ): void {
        ProfessionalDeletionAuditEntry::create([
            'professional_id' => $professional->id,
            'professional_handle_snapshot' => (string) ($professional->handle ?? ''),
            'professional_email_snapshot' => (string) ($professional->primary_email ?? ''),
            'event' => $event,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_handle_snapshot' => $actorHandle,
            'reason' => $reason,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => ! empty($metadata) ? $metadata : null,
        ]);
    }
}
