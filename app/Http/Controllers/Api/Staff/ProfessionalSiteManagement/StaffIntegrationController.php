<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Staff views third-party integration status for any professional. Uses raw query to avoid exposing encrypted token columns that are hidden on the model.
class StaffIntegrationController extends ApiController
{
    /**
     * GET /api/staff/professionals/{professional}/integrations
     *
     * @return JsonResponse{ integrations: array<int, array{id: string, provider: string, external_account_id: string|null, last_catalog_sync_at: string|null, last_catalog_sync_error: string|null, expires_at: string|null, connected_at: string|null}> }
     */
    public function index(Request $request, Professional $professional): JsonResponse
    {
        $rows = DB::table('core.professional_integrations')
            ->where('professional_id', $professional->id)
            ->get([
                'id', 'provider', 'external_account_id',
                'last_catalog_sync_at', 'last_catalog_sync_error',
                'expires_at', 'created_at',
            ]);

        $integrations = $rows->map(fn (object $row): array => [
            'id' => $row->id,
            'provider' => $row->provider,
            'external_account_id' => $row->external_account_id,
            'last_catalog_sync_at' => $row->last_catalog_sync_at,
            'last_catalog_sync_error' => $row->last_catalog_sync_error,
            'expires_at' => $row->expires_at,
            'connected_at' => $row->created_at,
        ])->values()->all();

        return $this->success(['integrations' => $integrations]);
    }
}
