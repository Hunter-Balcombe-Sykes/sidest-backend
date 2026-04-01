<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Requests\Api\Professional\Store\RefreshBrandAffiliateSegmentRequest;
use App\Http\Requests\Api\Professional\Store\StoreBrandAffiliateSegmentRequest;
use App\Http\Requests\Api\Professional\Store\UpdateBrandAffiliateSegmentRequest;
use App\Models\Retail\BrandAffiliateSegment;
use App\Services\Store\BrandAccessService;
use App\Services\Store\SegmentEvaluationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrandAffiliateSegmentController extends ApiController
{
    use ResolveCurrentProfessional;

    private const MAX_SEGMENTS_PER_BRAND = 50;

    public function __construct(
        private readonly BrandAccessService $brandAccess,
        private readonly SegmentEvaluationService $evaluator,
    ) {}

    /**
     * GET /store/affiliate-segments
     * List all segments for the brand with member counts.
     */
    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        [$brandId, $error] = $this->resolveBrandId($request, $pro);
        if ($error !== null) {
            return $error;
        }

        $segments = BrandAffiliateSegment::query()
            ->withCount('members')
            ->where('brand_professional_id', $brandId)
            ->orderBy('created_at')
            ->get();

        return $this->success($segments->map(fn ($s) => $this->buildPayload($s))->values()->all());
    }

    /**
     * POST /store/affiliate-segments
     * Create a segment and immediately evaluate its membership.
     */
    public function store(StoreBrandAffiliateSegmentRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $validated = $request->validated();

        $brandId = trim((string) $validated['brand_professional_id']);
        $name = trim((string) $validated['name']);

        if (! $this->brandAccess->canManageBrand($pro, $brandId)) {
            return $this->error('You are not permitted to manage settings for this brand.', 403);
        }

        try {
            $segment = DB::transaction(function () use ($brandId, $validated, $name): ?BrandAffiliateSegment {
                DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['segments:' . $brandId]);

                $existingCount = BrandAffiliateSegment::query()
                    ->where('brand_professional_id', $brandId)
                    ->count();

                if ($existingCount >= self::MAX_SEGMENTS_PER_BRAND) {
                    return null;
                }

                return BrandAffiliateSegment::create([
                    'brand_professional_id' => $brandId,
                    'name' => $name,
                    'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
                    'criteria' => (string) $validated['criteria'],
                    'size' => (int) $validated['size'],
                    'lookback_days' => isset($validated['lookback_days']) ? (int) $validated['lookback_days'] : null,
                    'professional_type_filter' => isset($validated['professional_type_filter']) ? trim((string) $validated['professional_type_filter']) : null,
                ]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                return $this->error('A segment with this name already exists for this brand.', 422);
            }

            throw $e;
        }

        if (! $segment instanceof BrandAffiliateSegment) {
            return $this->error('Maximum of ' . self::MAX_SEGMENTS_PER_BRAND . ' segments per brand reached.', 422);
        }

        $this->evaluator->evaluate($segment);
        $segment->loadCount('members');

        return $this->success($this->buildPayload($segment), 201);
    }

    /**
     * GET /store/affiliate-segments/{segmentId}
     * Get segment detail with current members.
     */
    public function show(Request $request, string $segmentId): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $segment = BrandAffiliateSegment::query()
            ->withCount('members')
            ->with(['members.affiliateProfessional:id,display_name,handle,professional_type'])
            ->find($segmentId);

        if (! $segment) {
            return $this->error('Segment not found.', 404);
        }

        if (! $this->brandAccess->canManageBrand($pro, (string) $segment->brand_professional_id)) {
            return $this->error('You are not permitted to manage settings for this brand.', 403);
        }

        return $this->success($this->buildPayload($segment, true));
    }

    /**
     * PATCH /store/affiliate-segments/{segmentId}
     * Update segment criteria/size/name and re-evaluate membership.
     */
    public function update(UpdateBrandAffiliateSegmentRequest $request, string $segmentId): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $validated = $request->validated();

        $segment = BrandAffiliateSegment::find($segmentId);

        if (! $segment) {
            return $this->error('Segment not found.', 404);
        }

        if (! $this->brandAccess->canManageBrand($pro, (string) $segment->brand_professional_id)) {
            return $this->error('You are not permitted to manage settings for this brand.', 403);
        }

        if (array_key_exists('brand_professional_id', $validated)
            && (string) $validated['brand_professional_id'] !== (string) $segment->brand_professional_id) {
            return $this->error('brand_professional_id cannot be changed.', 422);
        }

        $finalCriteria = array_key_exists('criteria', $validated)
            ? (string) $validated['criteria']
            : (string) $segment->criteria;
        $finalTypeFilter = array_key_exists('professional_type_filter', $validated)
            ? (isset($validated['professional_type_filter']) ? trim((string) $validated['professional_type_filter']) : null)
            : $segment->professional_type_filter;

        if ($finalCriteria === 'professional_type' && ($finalTypeFilter === null || trim((string) $finalTypeFilter) === '')) {
            return $this->error('professional_type_filter is required when criteria is professional_type.', 422);
        }

        if (array_key_exists('name', $validated)) {
            $newName = trim((string) $validated['name']);

            $nameExists = BrandAffiliateSegment::query()
                ->where('brand_professional_id', (string) $segment->brand_professional_id)
                ->where('name', $newName)
                ->where('id', '!=', $segmentId)
                ->exists();

            if ($nameExists) {
                return $this->error('A segment with this name already exists for this brand.', 422);
            }

            $segment->name = $newName;
        }

        if (array_key_exists('description', $validated)) {
            $segment->description = isset($validated['description']) ? trim((string) $validated['description']) : null;
        }
        if (array_key_exists('criteria', $validated)) {
            $segment->criteria = (string) $validated['criteria'];
        }
        if (array_key_exists('size', $validated)) {
            $segment->size = (int) $validated['size'];
        }
        if (array_key_exists('lookback_days', $validated)) {
            $segment->lookback_days = isset($validated['lookback_days']) ? (int) $validated['lookback_days'] : null;
        }
        if (array_key_exists('professional_type_filter', $validated)) {
            $segment->professional_type_filter = $finalTypeFilter;
        }

        try {
            $segment->save();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                return $this->error('A segment with this name already exists for this brand.', 422);
            }

            throw $e;
        }

        $this->evaluator->evaluate($segment);
        $segment->loadCount('members');

        return $this->success($this->buildPayload($segment));
    }

    /**
     * DELETE /store/affiliate-segments/{segmentId}
     * Delete segment (cascades cached members).
     */
    public function destroy(Request $request, string $segmentId): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $segment = BrandAffiliateSegment::find($segmentId);

        if (! $segment) {
            return $this->error('Segment not found.', 404);
        }

        if (! $this->brandAccess->canManageBrand($pro, (string) $segment->brand_professional_id)) {
            return $this->error('You are not permitted to manage settings for this brand.', 403);
        }

        $segment->delete();

        return $this->success(['deleted' => true]);
    }

    /**
     * POST /store/affiliate-segments/{segmentId}/refresh
     * Force re-evaluate segment membership now.
     */
    public function refresh(RefreshBrandAffiliateSegmentRequest $request, string $segmentId): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $segment = BrandAffiliateSegment::withCount('members')->find($segmentId);

        if (! $segment) {
            return $this->error('Segment not found.', 404);
        }

        if (! $this->brandAccess->canManageBrand($pro, (string) $segment->brand_professional_id)) {
            return $this->error('You are not permitted to manage settings for this brand.', 403);
        }

        $this->evaluator->evaluate($segment);
        $segment->loadCount('members');

        return $this->success($this->buildPayload($segment));
    }

    /* ------------------------------------------------------------------ */
    /*  Private helpers                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{0: string, 1: JsonResponse|null}
     */
    private function resolveBrandId(Request $request, $pro): array
    {
        $requestedId = trim((string) $request->input('brand_professional_id', ''));

        if ($requestedId === '') {
            if ($this->brandAccess->isBrandProfessional($pro)) {
                $requestedId = (string) $pro->id;
            } else {
                return ['', $this->error('brand_professional_id is required for this account type.', 422)];
            }
        }

        if (! $this->brandAccess->canManageBrand($pro, $requestedId)) {
            return ['', $this->error('You are not permitted to manage settings for this brand.', 403)];
        }

        return [$requestedId, null];
    }

    private function buildPayload(BrandAffiliateSegment $segment, bool $withMembers = false): array
    {
        $payload = [
            'id' => (string) $segment->id,
            'brand_professional_id' => (string) $segment->brand_professional_id,
            'name' => (string) $segment->name,
            'description' => $segment->description,
            'criteria' => (string) $segment->criteria,
            'size' => (int) $segment->size,
            'lookback_days' => $segment->lookback_days !== null ? (int) $segment->lookback_days : null,
            'professional_type_filter' => $segment->professional_type_filter,
            'members_count' => (int) ($segment->members_count ?? 0),
            'members_refreshed_at' => $segment->members_refreshed_at?->toISOString(),
            'created_at' => $segment->created_at?->toISOString(),
            'updated_at' => $segment->updated_at?->toISOString(),
        ];

        if ($withMembers && $segment->relationLoaded('members')) {
            $payload['members'] = $segment->members->map(static fn ($m) => [
                'affiliate_professional_id' => (string) $m->affiliate_professional_id,
                'rank' => (int) $m->rank,
                'metric_value' => (int) $m->metric_value,
                'display_name' => $m->affiliateProfessional?->display_name,
                'handle' => $m->affiliateProfessional?->handle,
                'professional_type' => $m->affiliateProfessional?->professional_type,
            ])->values()->all();
        }

        return $payload;
    }
}
