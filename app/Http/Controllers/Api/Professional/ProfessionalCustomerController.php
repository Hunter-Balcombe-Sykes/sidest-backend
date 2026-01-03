<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Professional\Customer\StoreCustomerRequest;
use App\Http\Requests\Api\Professional\Customer\UpdateCustomerRequest;
use App\Models\Core\Professional\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;

class ProfessionalCustomerController extends Controller
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    public function index(Request $request)
    {
        $pro = $this->currentProfessional($request);

        $perPage = (int) $request->query('per_page', 25);
        if ($perPage < 1) $perPage = 25;
        if ($perPage > 100) $perPage = 100;

        $search = $request->query('search');
        $search = is_string($search) ? trim($search) : null;
        $like = $search ? '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%' : null;

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived    = $request->boolean('only_archived');

        $query = Customer::query()
            ->where('professional_id', $pro->id)
            ->orderByDesc('created_at');

        if ($onlyArchived) {
            $query->onlyTrashed();
        } elseif ($includeArchived) {
            $query->withTrashed();
        }

        if ($like) {
            // Postgres: like for case-insensitive search
            $query->where(function ($q) use ($like) {
                $q->where('full_name', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like);
            });
        }

        $paginator = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'customers' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
            ],
            'filters' => [
                'include_archived' => $includeArchived,
                'only_archived' => $onlyArchived,
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request)
    {
        $pro = $this->currentProfessional($request);

        $data = $request->validated();
        $data['source'] = $data['source'] ?? 'manual';

        $customer = $pro->customers()->create($data);

        return response()->json(['customer' => $customer], 201);

    }

    public function show(Request $request, Customer $customer)
    {
        $pro = $this->currentProfessional($request);
        abort_unless($customer->professional_id === $pro->id, 404);

        $includeArchived = $request->boolean('include_archived');
        if (!$includeArchived && method_exists($customer, 'trashed') && $customer->trashed()) {
            abort(404);
        }

        return response()->json(['customer' => $customer]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $pro = $this->currentProfessional($request);

        abort_unless($customer->professional_id === $pro->id, 404);
        if (method_exists($customer, 'trashed') && $customer->trashed()) { abort(404); }

        $customer->fill($request->validated());
        $customer->save();

        return response()->json(['customer' => $customer->fresh()]);
    }

    // Archive Soft Delete
    public function destroy(Request $request, Customer $customer)
    {
        $pro = $this->currentProfessional($request);
        abort_unless($customer->professional_id === $pro->id, 404);

        if (!$customer->trashed()) {
            $customer->delete(); // soft delete (archive)
        }

        return response()->json(['archived' => true]);
    }


    // Restore (un-archive)
    public function restore(Request $request, Customer $customer): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        abort_unless($customer->professional_id === $pro->id, 404);

        if (method_exists($customer, 'trashed') && $customer->trashed()) {
            $customer->restore();
        }

        return response()->json(['restored' => true, 'customer' => $customer->fresh()]);
    }

}
