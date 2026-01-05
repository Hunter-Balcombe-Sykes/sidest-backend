<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\ProfessionalSite\StaffUpdateCustomerRequest;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffCustomerManagementController extends ApiController
{
    /**
     * GET /api/staff/professionals/{professional}/customers?q=...&per_page=...&page=...
     */
    public function index(Request $request, Professional $professional): JsonResponse
    {
        $q = trim((string) $request->query('q', $request->query('search', '')));
        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min(100, $perPage));

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived    = $request->boolean('only_archived');

        $query = Customer::query()
            ->where('professional_id', $professional->id)
            ->orderByDesc('created_at');

        if ($onlyArchived) {
            $query->onlyTrashed();
        } elseif ($includeArchived) {
            $query->withTrashed();
        }

        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';

            $query->where(function ($qq) use ($like) {
                $qq->where('full_name', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like);
            });
        }

        $page = $query->paginate($perPage)->appends($request->query());

        return $this->success([
            'customers' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
            'filters' => [
                'include_archived' => $includeArchived,
                'only_archived' => $onlyArchived,
            ],
        ]);
    }

    /**
     * GET /api/staff/professionals/{professional}/customers/{id}
     */
    public function show(Request $request, Professional $professional, Customer $customer): JsonResponse
    {
        $includeArchived = $request->boolean('include_archived');

        if (!$includeArchived && $customer->trashed()) {
            abort(404);
        }

        return $this->success(['customer' => $customer]);
    }

    /**
     * PATCH /api/staff/professionals/{professional}/customers/{id}
     */
    public function update(StaffUpdateCustomerRequest $request, Professional $professional, Customer $customer): JsonResponse
    {
        if ($customer->trashed()) { abort(404); }

        $customer->fill($request->validated());
        $customer->save();

        return $this->success(['customer' => $customer->fresh()]);
    }

    /**
     * DELETE /api/staff/professionals/{professional}/customers/{id}
     */
    public function destroy(Professional $professional, Customer $customer): JsonResponse
    {
        if (!$customer->trashed()) { $customer->delete(); }

        return $this->success(['archived' => true]);
    }

    public function restore(Professional $professional, Customer $customer): JsonResponse
    {
        if ($customer->trashed()) { $customer->restore(); }

        return $this->success(['restored' => true, 'customer' => $customer->fresh()]);
    }

    public function forceDestroy(Professional $professional, Customer $customer): JsonResponse
    {
        $customer->forceDelete();

        return $this->success(['deleted' => true]);
    }

}
