<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Staff\ProfessionalSite\StaffUpdateProfessionalRequest;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffProfessionalController extends Controller
{
    /**
     * GET /api/staff/professionals?q=...&status=...&per_page=...
     */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status'); // optional: active|suspended
        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min(100, $perPage));

        $query = Professional::query()
            ->with(['site.theme'])
            ->orderByDesc('created_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        if ($q !== '') {
            $like = '%' . $q . '%';

            $query->where(function ($qq) use ($like) {
                $qq->whereRaw('handle ILIKE ?', [$like])
                    ->orWhereRaw('display_name ILIKE ?', [$like])
                    ->orWhereRaw('primary_email ILIKE ?', [$like])
                    ->orWhereRaw('phone ILIKE ?', [$like])
                    ->orWhereRaw('first_name ILIKE ?', [$like])
                    ->orWhereRaw('last_name ILIKE ?', [$like])
                    ->orWhereHas('site', function ($s) use ($like) {
                        $s->whereRaw('subdomain ILIKE ?', [$like]);
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

        return response()->json([
            'professionals' => $professionals,
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/staff/professionals/{professional}
     */
    public function show(Professional $professional): JsonResponse
    {
        $professional->load(['site.theme']);

        return response()->json([
            'professional' => [
                'id'            => $professional->id,
                'auth_user_id'  => $professional->auth_user_id,
                'handle'        => $professional->handle,
                'display_name'  => $professional->display_name,
                'bio'           => $professional->bio,
                'country_code'  => $professional->country_code,
                'timezone'      => $professional->timezone,
                'status'        => $professional->status,
                'onboarding_step' => $professional->onboarding_step,
                'primary_email' => $professional->primary_email,
                'phone'         => $professional->phone,
                'public_contact_number' => $professional->public_contact_number,
                'public_contact_email' => $professional->public_contact_email,
                'icon_bucket'  => $professional->icon_bucket,
                'icon_path'    => $professional->icon_path,
                'headshot_bucket' => $professional->headshot_bucket,
                'headshot_path'   => $professional->headshot_path,
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

        return response()->json([
            'professional' => $professional->fresh(),
        ]);
    }

    public function update(StaffUpdateProfessionalRequest $request, Professional $professional)
    {
        $professional->fill($request->validated());
        $professional->save();

        return response()->json([
            'professional' => $professional->fresh(),
        ]);
    }

}
