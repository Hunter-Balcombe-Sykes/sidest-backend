<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;

class BrandPartnerController extends ApiController
{
    public function index(): JsonResponse
    {
        $brands = Professional::query()
            ->where('professional_type', 'brand')
            ->where('status', 'active')
            ->with('site')
            ->orderByRaw('COALESCE(display_name, handle) asc')
            ->get()
            ->map(function (Professional $professional): array {
                return [
                    'id' => $professional->id,
                    'display_name' => $professional->display_name,
                    'handle' => $professional->handle,
                    'subdomain' => $professional->site?->subdomain,
                ];
            })
            ->values()
            ->all();

        return $this->success([
            'brands' => $brands,
        ]);
    }
}
