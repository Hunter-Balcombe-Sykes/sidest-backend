<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Jobs\Square\SyncSquareCatalogDeltaJob;
use App\Models\Core\Professional\Service;
use App\Services\Square\SquareServiceSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SquareIntegrationController extends ApiController
{
    use ResolveCurrentProfessional;

    /**
     * GET /api/square/status
     * Returns whether the user has Square connected and when it expires.
     */
    public function status(Request $request)
    {
        $pro = $this->currentProfessional($request);

        $connected = !empty($pro->square_access_token) && !empty($pro->square_merchant_id);

        return $this->success([
            'connected' => $connected,
            'merchant_id' => $connected ? $pro->square_merchant_id : null,
            'expires_at' => $connected && $pro->square_expires_at
                ? $pro->square_expires_at->toIso8601String()
                : null,
        ]);
    }

    /**
     * POST /api/square/connect
     * Stores the Square OAuth tokens for this professional.
     */
    public function connect(Request $request)
    {
        $request->validate([
            'access_token'  => 'required|string',
            'refresh_token' => 'required|string',
            'merchant_id'   => 'required|string',
            'expires_at'    => 'required|string',
        ]);

        $pro = $this->currentProfessional($request);

        $pro->square_access_token  = $request->input('access_token');
        $pro->square_refresh_token = $request->input('refresh_token');
        $pro->square_merchant_id   = $request->input('merchant_id');
        $pro->square_expires_at    = $request->input('expires_at');
        $pro->square_last_catalog_sync_error = null;
        $pro->save();

        // Pull initial services from Square right after connect.
        SyncSquareCatalogDeltaJob::dispatch($pro->square_merchant_id, null, true);

        Log::info('Square connected', [
            'professional_id' => $pro->id,
            'merchant_id'     => $pro->square_merchant_id,
        ]);

        return $this->success([
            'connected'   => true,
            'merchant_id' => $pro->square_merchant_id,
            'expires_at'  => $pro->square_expires_at->toIso8601String(),
        ]);
    }

    /**
     * POST /api/square/disconnect
     * Clears the stored Square tokens for this professional.
     */
    public function disconnect(Request $request)
    {
        $pro = $this->currentProfessional($request);

        $pro->square_access_token  = null;
        $pro->square_refresh_token = null;
        $pro->square_merchant_id   = null;
        $pro->square_expires_at    = null;
        $pro->square_catalog_latest_time = null;
        $pro->square_last_catalog_sync_at = null;
        $pro->square_last_catalog_sync_error = null;
        $pro->save();

        Log::info('Square disconnected', [
            'professional_id' => $pro->id,
        ]);

        return $this->success([
            'connected' => false,
        ]);
    }

    /**
     * GET /api/square/token
     * Returns the decrypted Square access token for frontend use.
     */
    public function token(Request $request)
    {
        $pro = $this->currentProfessional($request);

        if (empty($pro->square_access_token)) {
            return $this->error('Square account not connected.', 404);
        }

        return $this->success([
            'access_token' => $pro->square_access_token,
            'expires_at'   => $pro->square_expires_at
                ? $pro->square_expires_at->toIso8601String()
                : null,
        ]);
    }

    /**
     * POST /api/square/services/sync
     * Queues a full pull from Square services into Commet.
     */
    public function syncServicesNow(Request $request)
    {
        $pro = $this->currentProfessional($request);

        if (empty($pro->square_access_token) || empty($pro->square_merchant_id)) {
            return $this->error('Square account not connected.', 404);
        }

        SyncSquareCatalogDeltaJob::dispatch($pro->square_merchant_id, null, true);

        return $this->success([
            'queued' => true,
            'merchant_id' => $pro->square_merchant_id,
        ]);
    }

    /**
     * POST /api/square/services/{service}/push
     * Pushes one local service update to Square immediately.
     */
    public function pushServiceNow(Request $request, Service $service, SquareServiceSyncService $syncService)
    {
        $pro = $this->currentProfessional($request);

        abort_unless($service->professional_id === $pro->id, 404);

        if (empty($pro->square_access_token) || empty($pro->square_merchant_id)) {
            return $this->error('Square account not connected.', 404);
        }

        try {
            $syncService->pushServiceToSquare($service, 'upsert');
        } catch (\Throwable $e) {
            Log::warning('Manual Square push failed', [
                'professional_id' => $pro->id,
                'service_id' => $service->id,
                'message' => $e->getMessage(),
            ]);

            return $this->error($e->getMessage(), 422);
        }

        $fresh = Service::query()->withTrashed()->find($service->id);

        return $this->success([
            'pushed' => true,
            'service_id' => $service->id,
            'square_catalog_object_id' => $fresh?->square_catalog_object_id,
            'square_variation_id' => $fresh?->square_variation_id,
            'square_last_synced_at' => $fresh?->square_last_synced_at?->toIso8601String(),
            'square_sync_error' => $fresh?->square_sync_error,
        ]);
    }
}
