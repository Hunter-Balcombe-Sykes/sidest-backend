<?php

namespace App\Http\Controllers\Api\Staff\FeatureFlag;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\FeatureFlag\CreateOverrideRequest;
use App\Http\Resources\FeatureFlagOverrideResource;
use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Services\FeatureFlags\FeatureFlagService;
use App\Services\FeatureFlags\OverrideScope;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff admin endpoints to set / clear per-professional or per-brand flag overrides.
class StaffFeatureFlagOverrideController extends ApiController
{
    public function __construct(private FeatureFlagService $service) {}

    /** GET /staff/feature-flags/{key}/overrides — list overrides for a flag (paginated, newest first). */
    public function index(Request $request, string $key): JsonResponse
    {
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');

        $flag = FeatureFlag::findOrFail($key);
        $overrides = $flag->overrides()->orderBy('created_at', 'desc')->paginate(50);

        return FeatureFlagOverrideResource::collection($overrides)->response();
    }

    /** POST /staff/feature-flags/{key}/overrides — upsert an override for a brand or professional. */
    public function store(CreateOverrideRequest $request, string $key): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        abort_if($staff === null, 401, 'Unauthenticated');

        $flag = FeatureFlag::findOrFail($key);
        $data = $request->validated();

        $scope = ($data['brand_id'] ?? null)
            ? OverrideScope::forBrand($data['brand_id'])
            : OverrideScope::forProfessional($data['professional_id']);

        $this->service->setOverride(
            $key,
            $scope,
            (bool) $data['enabled'],
            $data['reason'] ?? null,
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            $staff->id,
        );

        // Fetch the upserted row to return in the response.
        $created = FeatureFlagOverride::where('flag_key', $key)
            ->when($scope->brandId, fn ($q) => $q->where('brand_id', $scope->brandId))
            ->when($scope->professionalId, fn ($q) => $q->where('professional_id', $scope->professionalId)->whereNull('brand_id'))
            ->first();

        return (new FeatureFlagOverrideResource($created))->response()->setStatusCode(201);
    }

    /** DELETE /staff/feature-flags/overrides/{id} — remove an override by its UUID. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');

        $override = FeatureFlagOverride::findOrFail($id);

        // Delete by PK (unambiguous), then push-invalidate the scope's cache key.
        $override->delete();

        if ($override->brand_id !== null) {
            $this->service->forgetBrand($override->brand_id);
        } else {
            $this->service->forgetPro($override->professional_id);
        }

        return response()->json(null, 204);
    }
}
