<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Professional\Services\ReorderServiceRequest;
use App\Http\Requests\Api\Professional\Services\StoreServiceRequest;
use App\Http\Requests\Api\Professional\Services\UpdateServiceRequest;
use App\Models\Core\Professional\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
class ProfessionalServiceController extends Controller
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived    = $request->boolean('only_archived');

        $query = Service::query()
            ->where('professional_id', $pro->id);

        if ($onlyArchived) {
            $query->onlyTrashed();
        } elseif ($includeArchived) {
            $query->withTrashed();
        }

        $services = $query->orderBy('sort_order')->orderBy('created_at')->get();

        return response()->json([
            'services' => $services,
            'filters' => [
        'include_archived' => $includeArchived,
        'only_archived' => $onlyArchived,
                ],
        ]);
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $data = $request->validated();

        $service = DB::transaction(function () use ($pro, $data) {
            // One-at-a-time service writes per professional
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["services:{$pro->id}"]);

            if (!array_key_exists('sort_order', $data) || $data['sort_order'] === null) {
                $max = Service::query()
                    ->where('professional_id', $pro->id)
                    ->max('sort_order');

                $data['sort_order'] = is_null($max) ? 0 : ((int)$max + 1);
            }

            $service = Service::query()->create([
                'professional_id'   => $pro->id,
                'title'             => $data['title'],
                'category'          => $data['category'] ?? null,
                'description'       => $data['description'] ?? null,
                'price_cents'       => $data['price_cents'],
                'currency_code'     => $data['currency_code'] ?? 'AUD',
                'duration_minutes'  => $data['duration_minutes'] ?? null,
                'is_active'         => $data['is_active'] ?? true,
                'sort_order'        => $data['sort_order'],
            ]);

            return $service->fresh();

        });

        return response()->json(['service' => $service], 201);
    }

    public function show(Request $request, Service $service): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        abort_unless($service->professional_id === $pro->id, 404);

        return response()->json(['service' => $service]);
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        abort_unless($service->professional_id === $pro->id, 404);

        $service->fill($request->validated());
        $service->save();

        return response()->json(['service' => $service->fresh()]);
    }

    public function destroy(Request $request, Service $service): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        abort_unless($service->professional_id === $pro->id, 404);

        $service->delete();

        return response()->json(['deleted' => true]);
    }

    public function reorder(ReorderServiceRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $ids = array_values(array_unique($request->validated()['ids']));

        DB::transaction(function () use ($pro, $ids) {

            $allIds = Service::query()
                ->where('professional_id', $pro->id)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            // Validate: every provided id belongs to this professional
            $allSet = array_flip($allIds);
            foreach ($ids as $id) {
                if (!isset($allSet[$id])) {
                    abort(422, 'One or more service IDs are invalid.');
                }
            }

            // Provided first, then the rest
            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder = array_merge($ids, $remaining);

            foreach ($newOrder as $i => $id) {
                Service::query()
                    ->where('professional_id', $pro->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function restore(Request $request, Service $service): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        // Ownership guard
        abort_unless($service->professional_id === $pro->id, 404);

        // If it's not deleted, make it a no-op (nice for frontend retries)
        if (!$service->trashed()) {
            return response()->json(['restored' => true, 'service' => $service->fresh()]);
        }

        DB::transaction(function () use ($pro, $service) {
            $service->restore();

            // Optional but recommended:
            // put restored service at the end to avoid sort_order collisions
            $max = Service::query()
                ->where('professional_id', $pro->id)
                ->max('sort_order');

            $service->sort_order = is_null($max) ? 0 : ($max + 1);
            $service->save();

        });

        return response()->json(['restored' => true, 'service' => $service->fresh()]);
    }

}
