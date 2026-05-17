<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Square\SquareTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// V2: Staff parity for Square integration — read status + admin force-disconnect.
// Mirrors the curated payload from SquareIntegrationController; skips connect/sync/push
// since those are user actions a brand must initiate (OAuth, credential consent).
class StaffSquareController extends ApiController
{
    public function __construct(
        private readonly SquareTokenService $squareTokenService,
    ) {}

    /**
     * GET /api/staff/professionals/{professional}/square/status
     */
    public function status(Request $request, Professional $professional): JsonResponse
    {
        $integration = $this->currentSquareIntegration($professional);
        $connected = $integration
            && ! empty($integration->access_token)
            && ! empty($integration->external_account_id);

        return $this->success([
            'connected' => $connected,
            'merchant_id' => $connected ? $integration->external_account_id : null,
            'expires_at' => $connected && $integration->expires_at
                ? $integration->expires_at->toIso8601String()
                : null,
        ]);
    }

    /**
     * POST /api/staff/professionals/{professional}/square/disconnect
     *
     * Revokes the OAuth token at Square (best-effort) and deletes the local
     * integration row. Idempotent — calling on a brand with no Square connection
     * still returns connected=false. Token-revoke failures are logged but don't
     * block the local delete (mirrors the self-service path).
     */
    public function disconnect(Request $request, Professional $professional): JsonResponse
    {
        $integration = $this->currentSquareIntegration($professional);
        $staff = $request->attributes->get('partna_staff');
        $actorStaffId = $staff ? (string) $staff->id : null;

        if ($integration) {
            try {
                $this->squareTokenService->revokeToken($integration);
            } catch (\Throwable $e) {
                Log::warning('Failed to revoke Square token at provider (staff disconnect)', [
                    'actor_staff_id' => $actorStaffId,
                    'brand_professional_id' => (string) $professional->id,
                    'error' => $e->getMessage(),
                ]);
            }

            ProfessionalIntegration::query()
                ->where('professional_id', $professional->id)
                ->where('provider', ProfessionalIntegration::PROVIDER_SQUARE)
                ->delete();
        }

        Log::info('Square disconnected by staff', [
            'actor_staff_id' => $actorStaffId,
            'brand_professional_id' => (string) $professional->id,
        ]);

        return $this->success([
            'connected' => false,
        ]);
    }

    private function currentSquareIntegration(Professional $professional): ?ProfessionalIntegration
    {
        return ProfessionalIntegration::query()
            ->where('professional_id', $professional->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SQUARE)
            ->first();
    }
}
