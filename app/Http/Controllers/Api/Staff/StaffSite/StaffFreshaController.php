<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Fresha\FreshaTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// V2: Staff parity for Fresha integration — read status + admin force-disconnect.
// Identical shape to StaffSquareController; the Fresha integration itself is still
// scaffolded-and-unverified (see project memory `fresha_integration_status`), so we
// expose status + disconnect only and leave sync/push as user actions.
class StaffFreshaController extends ApiController
{
    public function __construct(
        private readonly FreshaTokenService $freshaTokenService,
    ) {}

    /**
     * GET /api/staff/professionals/{professional}/fresha/status
     */
    public function status(Request $request, Professional $professional): JsonResponse
    {
        $integration = $this->currentFreshaIntegration($professional);
        $connected = $integration
            && ! empty($integration->access_token)
            && ! empty($integration->external_account_id);

        return $this->success([
            'connected' => $connected,
            'business_id' => $connected ? $integration->external_account_id : null,
            'expires_at' => $connected && $integration->expires_at
                ? $integration->expires_at->toIso8601String()
                : null,
        ]);
    }

    /**
     * POST /api/staff/professionals/{professional}/fresha/disconnect
     *
     * Revokes the OAuth token at Fresha (best-effort) and deletes the local
     * integration row. Idempotent. Token-revoke failures are logged but don't
     * block the local delete.
     */
    public function disconnect(Request $request, Professional $professional): JsonResponse
    {
        $integration = $this->currentFreshaIntegration($professional);
        $staff = $request->attributes->get('partna_staff');
        $actorStaffId = $staff ? (string) $staff->id : null;

        if ($integration) {
            try {
                $this->freshaTokenService->revokeToken($integration);
            } catch (\Throwable $e) {
                Log::warning('Failed to revoke Fresha token at provider (staff disconnect)', [
                    'actor_staff_id' => $actorStaffId,
                    'brand_professional_id' => (string) $professional->id,
                    'error' => $e->getMessage(),
                ]);
            }

            ProfessionalIntegration::query()
                ->where('professional_id', $professional->id)
                ->where('provider', ProfessionalIntegration::PROVIDER_FRESHA)
                ->delete();
        }

        Log::info('Fresha disconnected by staff', [
            'actor_staff_id' => $actorStaffId,
            'brand_professional_id' => (string) $professional->id,
        ]);

        return $this->success([
            'connected' => false,
        ]);
    }

    private function currentFreshaIntegration(Professional $professional): ?ProfessionalIntegration
    {
        return ProfessionalIntegration::query()
            ->where('professional_id', $professional->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_FRESHA)
            ->first();
    }
}
