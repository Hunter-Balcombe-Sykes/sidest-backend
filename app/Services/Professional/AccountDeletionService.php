<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalDeletionAuditEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // Token generation + mail sending implemented in later tasks.
        return ['success' => true, 'code' => 200];
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
