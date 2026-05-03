<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Resources\EnquiryResource;
use App\Models\Core\Site\Enquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Dashboard inbox for visitor-submitted enquiries. Read-only list + mark read/unread + soft-delete, scoped to the current professional.
class ProfessionalEnquiryController extends ApiController
{
    use ResolveCurrentProfessional;

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $page = Enquiry::query()
            ->where('professional_id', $pro->id)
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 20));

        return $this->success([
            'data' => EnquiryResource::collection($page->items())->toArray($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $enquiry = Enquiry::query()
            ->where('professional_id', $pro->id)
            ->find($id);

        if (! $enquiry) {
            return $this->error('Enquiry not found.', 404);
        }

        $request->validate([
            'read' => ['required', 'boolean'],
        ]);

        $enquiry->read_at = $request->boolean('read') ? now() : null;
        $enquiry->save();

        return $this->success([
            'enquiry' => (new EnquiryResource($enquiry))->toArray($request),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $enquiry = Enquiry::query()
            ->where('professional_id', $pro->id)
            ->find($id);

        if (! $enquiry) {
            return $this->error('Enquiry not found.', 404);
        }

        $enquiry->delete();

        return $this->success(['ok' => true]);
    }
}
