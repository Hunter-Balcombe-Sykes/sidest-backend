<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Staff\ProfessionalSite\Services\StaffReorderServiceRequest;
use App\Http\Requests\Api\Staff\ProfessionalSite\Services\StaffStoreServiceRequest;
use App\Http\Requests\Api\Staff\ProfessionalSite\Services\StaffUpdateServiceRequest;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffServiceManagementController extends Controller
{
    public function index(Request $request, Professional $professional): JsonResponse
    {
        $includeArchived = $request->boolean('include_archived');
        $onlyArchived    = $request->boolean('only_archived');

        $query = Service::query()
            ->where('professional_id', $professional->id);

        if ($onlyArchived) {
            $query->onlyTrashed();
        } elseif ($includeArchived) {
            $query->withTrashed();
        }

        $services = $query
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'services' => $services,
            'filters' => [
                'include_archived' => $includeArchived,
                'only_archived' => $onlyArchived,
                ],
            ]);
    }

    public function store(StaffStoreServiceRequest $request, Professional $professional): JsonResponse
    {
        $data = $request->validated();


        $service = DB::transaction(function () use ($professional, $data) {
            // One-at-a-time service writes per professional
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["services:{$professional->id}"]);
            if (!array_key_exists('sort_order', $data) || $data['sort_order'] === null) {
                $max = Service::query()
                    ->where('professional_id', $professional->id)
                    ->max('sort_order');

                $data['sort_order'] = is_null($max) ? 0 : ((int)$max + 1);
            }

            $service = Service::query()->create([
                'professional_id' => $professional->id,
                'title' => $data['title'],
                'category' => $data['category'] ?? null,
                'description' => $data['description'] ?? null,
                'price_cents' => $data['price_cents'],
                'currency_code' => $data['currency_code'] ?? 'AUD',
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => $data['sort_order'],
            ]);

            return $service->fresh();
        });

        return response()->json(['service' => $service], 201);
    }

    public function show(Request $request, Professional $professional, Service $service): JsonResponse
    {
        $includeArchived = $request->boolean('include_archived');

        if (!$includeArchived && $service->trashed()) { abort(404); }

        return response()->json(['service' => $service]);
    }

    public function update(StaffUpdateServiceRequest $request, Professional $professional, Service $service): JsonResponse
    {
        if ($service->trashed()) { abort(404); }

        $service->fill($request->validated());
        $service->save();

        return response()->json(['service' => $service->fresh()]);
    }

    public function destroy(Professional $professional, Service $service): JsonResponse
    {
        if ($service->trashed()) { abort(404); }
        $service->delete();

        return response()->json(['deleted' => true]);
    }

    public function reorder(StaffReorderServiceRequest $request, Professional $professional): JsonResponse
    {
        $ids = array_values(array_unique($request->validated()['ids'] ?? []));

        DB::transaction(function () use ($professional, $ids) {

            $allIds = Service::query()
                ->where('professional_id', $professional->id)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);

            foreach ($ids as $id) {
                if (!isset($allSet[$id])) {
                    abort(422, 'One or more service IDs are invalid.');
                }
            }

            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder  = array_merge($ids, $remaining);

            foreach ($newOrder as $i => $id) {
                Service::query()
                    ->where('professional_id', $professional->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        return response()->json(['ok' => true]);
    }


    public function forceDestroy(Professional $professional, Service $service): JsonResponse
    {
        // hard delete
        $service->forceDelete();

        return response()->json(['deleted' => true, 'hard' => true]);
    }

    public function restore(Professional $professional, Service $service): JsonResponse
    {
        if ($service->trashed()) { $service->restore(); }

        return response()->json(['restored' => true, 'service' => $service->fresh()]);
    }
}
