<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesSearchQueries;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Api\Professional\Customer\StoreCustomerRequest;
use App\Http\Requests\Api\Professional\Customer\UpdateCustomerRequest;
use App\Models\Core\Professional\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;

class ProfessionalCustomerController extends ApiController
{
    use HandlesSearchQueries;
    use NormalizesPerPage;
    use ReturnsPaginatedResponse;
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    public function index(Request $request)
    {
        $pro = $this->currentProfessional($request);

        $perPage = $this->normalizePerPage($request, 25, 100);
        $searchLike = $this->prepareSearchLike($request, 'search');

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived    = $request->boolean('only_archived');
        $marketingOptIn  = $request->query('marketing_opt_in');  // null, 'true', 'false'

        $query = Customer::query()
            ->where('professional_id', $pro->id)
            ->orderByDesc('created_at');

        if ($onlyArchived) {
            $query->onlyTrashed();
        } elseif ($includeArchived) {
            $query->withTrashed();
        }

        // Filter by marketing opt-in status (uses cached field for performance)
        if ($marketingOptIn !== null) {
            $isOptedIn = filter_var($marketingOptIn, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isOptedIn !== null) {
                $query->where('marketing_opt_in_cached', $isOptedIn);
            }
        }

        if ($searchLike) {
            // Postgres: like for case-insensitive search
            $query->where(function ($q) use ($searchLike) {
                $q->where('full_name', 'ilike', $searchLike)
                    ->orWhere('email', 'ilike', $searchLike)
                    ->orWhere('phone', 'ilike', $searchLike);
            });
        }

        $paginator = $query->paginate($perPage)->appends($request->query());

        $payload = $this->paginatedResponse($paginator, 'customers', [
            'filters' => [
                'include_archived' => $includeArchived,
                'only_archived' => $onlyArchived,
                'marketing_opt_in' => $marketingOptIn,
            ],
        ]);
        $payload['pagination'] = $payload['meta'];
        unset($payload['meta']);

        return $this->success($payload);
    }

    public function store(StoreCustomerRequest $request)
    {
        $pro = $this->currentProfessional($request);

        $data = $request->validated();
        $data['source'] = $data['source'] ?? 'manual';

        // Check if customer with this email already exists (excluding soft-deleted)
        $customer = $pro->customers()
            ->where('email', $data['email'])
            ->first();

        if ($customer) {
            // Update existing customer with new data
            $customer->update([
                'full_name' => $data['full_name'],
                'phone' => $data['phone'] ?? $customer->phone,
                'notes' => $data['notes'] ?? $customer->notes,
                'source' => $data['source'],
                'marketing_opt_in_cached' => $data['marketing_opt_in_cached'] ?? $customer->marketing_opt_in_cached,
            ]);
        } else {
            // Create new customer
            $customer = $pro->customers()->create($data);
        }

        return $this->success(['customer' => $customer], 201);

    }

    public function show(Request $request, Customer $customer)
    {
        $pro = $this->currentProfessional($request);
        abort_unless($customer->professional_id === $pro->id, 404);

        $includeArchived = $request->boolean('include_archived');
        if (!$includeArchived && method_exists($customer, 'trashed') && $customer->trashed()) {
            abort(404);
        }

        return $this->success(['customer' => $customer]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $pro = $this->currentProfessional($request);

        abort_unless($customer->professional_id === $pro->id, 404);
        if (method_exists($customer, 'trashed') && $customer->trashed()) { abort(404); }

        $customer->fill($request->validated());
        $customer->save();

        return $this->success(['customer' => $customer->fresh()]);
    }

    // Archive Soft Delete
    public function destroy(Request $request, Customer $customer)
    {
        $pro = $this->currentProfessional($request);
        abort_unless($customer->professional_id === $pro->id, 404);

        if (!$customer->trashed()) {
            $customer->delete(); // soft delete (archive)
        }

        return $this->success(['archived' => true]);
    }


    // Restore (un-archive)
    public function restore(Request $request, Customer $customer): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        abort_unless($customer->professional_id === $pro->id, 404);

        if (method_exists($customer, 'trashed') && $customer->trashed()) {
            $customer->restore();
        }

        return $this->success(['restored' => true, 'customer' => $customer->fresh()]);
    }

}
