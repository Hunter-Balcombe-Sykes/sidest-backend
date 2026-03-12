<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesSearchQueries;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Api\Staff\ProfessionalSite\StaffUpdateProfessionalRequest;
use App\Models\Core\Professional\Professional;
use App\Services\Enterprise\EnterpriseProvisioningService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffProfessionalController extends ApiController
{
    use HandlesSearchQueries;
    use NormalizesPerPage;
    use ReturnsPaginatedResponse;

    /**
     * GET /api/staff/professionals?q=...&status=...&per_page=...
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status'); // optional: active|suspended
        $professionalType = $request->query('professional_type'); // optional: barber|hairdresser|influencer|promoter|barbershop|salon
        $perPage = $this->normalizePerPage($request, 25, 100);
        $searchLike = $this->prepareSearchLike($request, 'q');

        $query = Professional::query()
            ->with(['site.theme'])
            ->orderByDesc('created_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        if (is_string($professionalType) && $professionalType !== '') {
            $query->where('professional_type', strtolower($professionalType));
        }

        if ($searchLike) {
            $query->where(function ($qq) use ($searchLike) {
                $qq->whereRaw('handle ILIKE ?', [$searchLike])
                    ->orWhereRaw('display_name ILIKE ?', [$searchLike])
                    ->orWhereRaw('primary_email ILIKE ?', [$searchLike])
                    ->orWhereRaw('phone ILIKE ?', [$searchLike])
                    ->orWhereRaw('first_name ILIKE ?', [$searchLike])
                    ->orWhereRaw('last_name ILIKE ?', [$searchLike])
                    ->orWhereHas('site', function ($s) use ($searchLike) {
                        $s->whereRaw('subdomain ILIKE ?', [$searchLike]);
                    });
            });
        }

        $page = $query->paginate($perPage);

        // Keep response light for list-view
        $professionals = $page->getCollection()->map(function (Professional $p) {
            $site = $p->site;
            $theme = $site?->theme;

            return [
                'id'            => $p->id,
                'handle'        => $p->handle,
                'display_name'  => $p->display_name,
                'professional_type' => $p->professional_type,
                'status'        => $p->status,
                'primary_email' => $p->primary_email,
                'phone'         => $p->phone,
                'created_at'    => optional($p->created_at)->toISOString(),
                'updated_at'    => optional($p->updated_at)->toISOString(),

                'site' => $site ? [
                    'id'           => $site->id,
                    'subdomain'    => $site->subdomain,
                    'is_published' => (bool) $site->is_published,
                    'theme'        => $theme ? [
                        'id'   => $theme->id,
                        'key'  => $theme->key ?? null,
                        'name' => $theme->name ?? null,
                    ] : null,
                ] : null,
            ];
        });

        $payload = $this->paginatedResponse($page, 'professionals');
        $payload['professionals'] = $professionals;

        return $this->success($payload);
    }

    /**
     * GET /api/staff/professionals/{professional}
     */
    public function show(Professional $professional): JsonResponse
    {
        $professional->load(['site.theme', 'services', 'blocks']);

        return $this->success([
            'professional' => [
                'id'            => $professional->id,
                'auth_user_id'  => $professional->auth_user_id,
                'handle'        => $professional->handle,
                'display_name'  => $professional->display_name,
                'bio'           => $professional->bio,
                'country_code'  => $professional->country_code,
                'timezone'      => $professional->timezone,
                'professional_type' => $professional->professional_type,
                'status'        => $professional->status,
                'onboarding_step' => $professional->onboarding_step,
                'primary_email' => $professional->primary_email,
                'phone'         => $professional->phone,
                'public_contact_number' => $professional->public_contact_number,
                'public_contact_email' => $professional->public_contact_email,
                'location_street_address' => $professional->location_street_address,
                'location_city' => $professional->location_city,
                'location_state' => $professional->location_state,
                'location_postcode' => $professional->location_postcode,
                'location_country' => $professional->location_country,
                'first_name'    => $professional->first_name,
                'last_name'     => $professional->last_name,
                'created_at'    => optional($professional->created_at)->toISOString(),
                'updated_at'    => optional($professional->updated_at)->toISOString(),
            ],
            'site' => $professional->site ? [
                'id'           => $professional->site->id,
                'subdomain'    => $professional->site->subdomain,
                'is_published' => (bool) $professional->site->is_published,
                'theme'        => $professional->site->theme ? [
                    'id'   => $professional->site->theme->id,
                    'key'  => $professional->site->theme->key ?? null,
                    'name' => $professional->site->theme->name ?? null,
                ] : null,
            ] : null,
        ]);
    }

    /**
     * PATCH /api/staff/professionals/{professional}/status
     * Body: { "status": "active" | "suspended" }
     */
    public function updateStatus(Request $request, Professional $professional): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended'],
        ]);

        $professional->status = $data['status'];
        $professional->save();

        return $this->success([
            'professional' => $professional->fresh(),
        ]);
    }

    public function update(
        StaffUpdateProfessionalRequest $request,
        Professional $professional,
        EnterpriseProvisioningService $enterpriseProvisioningService
    )
    {
        DB::transaction(function () use ($professional, $request, $enterpriseProvisioningService): void {
            $professional->fill($request->validated());
            $professional->save();

            if ($enterpriseProvisioningService->isEnterpriseProfessionalType($professional->professional_type)) {
                $enterpriseProvisioningService->ensureForProfessional($professional);
            }
        });

        return $this->success([
            'professional' => $professional->fresh(),
        ]);
    }

    /**
     * Soft delete - Normal staff operation
     * DELETE /api/staff/professionals/{professional}
     */
    public function destroy(Professional $professional): JsonResponse
    {
        // Soft delete (sets deleted_at)
        if (! $professional->trashed()) {
            $professional->delete();
        }

        return $this->success([
            'message' => 'Professional archived successfully',
            'archived' => true
        ]);
    }

    public function restore(Professional $professional): JsonResponse
    {
        if ($professional->trashed()) {
            $professional->restore();
        }

        return $this->success([
            'message' => 'Professional restored successfully',
            'professional' => $professional->fresh()
        ]);
    }

    public function forceDestroy(Professional $professional): JsonResponse
    {

        // Hard delete - PERMANENT
        $handle = $professional->handle;

        try {
            $professional->forceDelete();

            return $this->success([
                'message' => "Professional '{$handle}' permanently deleted",
                'permanently_deleted' => true
            ]);
        } catch (Exception $e) {
            return $this->error(
                'Cannot delete:  Professional has related data that must be removed first.',
                409
            );
        }
    }

}
