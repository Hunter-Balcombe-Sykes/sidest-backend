<?php

namespace App\Services\Professional;

use App\Mail\Notifications\AccountDeletionCancelledMail;
use App\Mail\Notifications\AccountDeletionRequestedMail;
use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalDeletionAuditEntry;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

        $retentionDays = (int) config('sidest.soft_delete_retention_days', 30);
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
            // Do not fail the confirm — the deletion itself is more important
            // than the mail delivery. Cancel flow is still available via logged-in session.
        }

        $this->logAuditEvent($professional, ProfessionalDeletionAuditEntry::EVENT_CONFIRMED, $request);

        return [
            'success' => true,
            'code' => 200,
            'deletes_at' => $deletesAt->toIso8601String(),
        ];
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
            ProfessionalDeletionAuditEntry::create([
                'professional_id' => $professional->id,
                'professional_handle_snapshot' => $handleSnapshot,
                'professional_email_snapshot' => $emailSnapshot,
                'event' => ProfessionalDeletionAuditEntry::EVENT_PURGE_FAILED,
                'metadata' => ['reason' => 'supabase_deletion_failed'],
                'created_at' => now(),
            ]);

            return false;
        }

        // Step 2: hard-delete professional row. DB handles cascades (42 FKs CASCADE,
        // 3 previously-RESTRICT FKs now SET NULL). forceDelete triggers model events.
        try {
            $professional->forceDelete();
        } catch (\Throwable $e) {
            Log::error('Professional forceDelete failed during purge', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
            ProfessionalDeletionAuditEntry::create([
                'professional_id' => $professional->id,
                'professional_handle_snapshot' => $handleSnapshot,
                'professional_email_snapshot' => $emailSnapshot,
                'event' => ProfessionalDeletionAuditEntry::EVENT_PURGE_FAILED,
                'metadata' => ['reason' => 'force_delete_failed', 'error' => $e->getMessage()],
                'created_at' => now(),
            ]);

            return false;
        }

        // Step 3: audit row — professional_id FK is SET NULL, snapshots preserve identity.
        ProfessionalDeletionAuditEntry::create([
            'professional_id' => null,
            'professional_handle_snapshot' => $handleSnapshot,
            'professional_email_snapshot' => $emailSnapshot,
            'event' => ProfessionalDeletionAuditEntry::EVENT_PURGED,
            'created_at' => now(),
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

        if ((int) ($professional->stripe_manual_balance_cents ?? 0) > 0) {
            $reasons[] = 'unpaid_balance';
        }

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

        $hasPendingTopups = DB::connection('pgsql')
            ->table('commerce.brand_commission_topups')
            ->where('brand_professional_id', $professional->id)
            ->where('status', '!=', 'completed')
            ->exists();

        if ($hasPendingTopups) {
            $reasons[] = 'pending_topups';
        }

        return $reasons;
    }

    /**
     * Schedule Stripe subscription to cancel at the end of the current billing
     * period. Best effort — log and continue on failure.
     */
    private function cancelStripeAtPeriodEnd(Professional $professional): void
    {
        try {
            $subscription = DB::connection('pgsql')
                ->table('billing.subscriptions')
                ->where('professional_id', $professional->id)
                ->whereNotNull('stripe_subscription_id')
                ->first();

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
            $subscription = DB::connection('pgsql')
                ->table('billing.subscriptions')
                ->where('professional_id', $professional->id)
                ->whereNotNull('stripe_subscription_id')
                ->first();

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
     * Append an audit row. Captures handle/email snapshots so the row survives
     * the professional's eventual hard delete.
     */
    public function logAuditEvent(
        Professional $professional,
        string $event,
        ?Request $request = null,
        array $metadata = []
    ): void {
        ProfessionalDeletionAuditEntry::create([
            'professional_id' => $professional->id,
            'professional_handle_snapshot' => (string) ($professional->handle ?? ''),
            'professional_email_snapshot' => (string) ($professional->primary_email ?? ''),
            'event' => $event,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => ! empty($metadata) ? $metadata : null,
            'created_at' => now(),
        ]);
    }
}
