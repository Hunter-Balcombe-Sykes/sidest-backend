<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// Generates a short-lived connection code so a brand can link their
// Shopify store (via the embedded app) to their existing Side St account.
// The code is stored in Redis for 30 minutes and consumed exactly once.
class ShopifyEmbeddedConnectionController extends ApiController
{
    use ResolveCurrentProfessional;

    /**
     * Generate a one-time connection code for the Shopify embedded app.
     *
     * @return array{code: string, expires_in: int}
     */
    public function generate(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        // 32-char random code — guessing probability negligible within 30 min window.
        $code = Str::random(32);
        Cache::put("shopify:embed:connect:{$code}", (string) $professional->id, now()->addMinutes(30));

        return $this->success([
            'code' => $code,
            'expires_in' => 1800, // seconds
        ]);
    }
}
