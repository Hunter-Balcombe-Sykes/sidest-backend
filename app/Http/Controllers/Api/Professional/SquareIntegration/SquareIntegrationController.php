<?php

namespace App\Http\Controllers\Api\Professional\SquareIntegration;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Jobs\Square\SyncSquareCatalogDeltaJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Professional\Service;
use App\Services\Square\SquareApiException;
use App\Services\Square\SquareServiceSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// V2: Square POS connection for booking/service sync. Unrelated to V2 commerce — booking integration only.
class SquareIntegrationController extends ApiController
{
    use ResolveCurrentProfessional;

    private function currentSquareIntegration(Request $request): ?ProfessionalIntegration
    {
        $pro = $this->currentProfessional($request);

        return $pro->integrationForProvider(ProfessionalIntegration::PROVIDER_SQUARE);
    }

    /**
     * Check if the professional has Square connected.
     */
    private function ensureSquareConnected(Request $request): JsonResponse|null
    {
        $integration = $this->currentSquareIntegration($request);
        if (! $integration) {
            return $this->error('Square account not connected.', 404);
        }

        if (empty($integration->access_token) || empty($integration->external_account_id)) {
            return $this->error('Square account not connected.', 404);
        }

        return null; // Success: connection exists
    }

    /**
     * Build user-friendly error message from Square API exception.
     */
    private function buildSquareErrorMessage(SquareApiException $e): array
    {
        $rawMessage = trim($e->getMessage());
        $message = $rawMessage !== '' ? $rawMessage : 'Square sync failed.';
        $lower = strtolower($message);
        $status = $e->status > 0 ? $e->status : 422;

        $shouldSuggestReconnect =
            str_contains($lower, 'resource not found') ||
            str_contains($lower, 'unauthorized') ||
            str_contains($lower, 'access token') ||
            str_contains($lower, 'merchant');

        if ($shouldSuggestReconnect) {
            $message .= ' Please reconnect your Square account. If this persists, verify SQUARE_ENVIRONMENT matches the account mode (production vs sandbox).';
            $status = 409;
        }

        return [$message, $status];
    }

    /**
     * GET /api/square/status
     * Returns whether the user has Square connected and when it expires.
     */
    public function status(Request $request)
    {
        $integration = $this->currentSquareIntegration($request);
        $connected = $integration
            && ! empty($integration->access_token)
            && ! empty($integration->external_account_id);

        return $this->success([
            'connected' => $connected,
            'merchant_id' => $connected ? $integration->external_account_id : null,
            'expires_at' => $connected && $integration->expires_at
                ? $integration->expires_at->toIso8601String()
                : null,
        ]);
    }

    /**
     * POST /api/square/connect
     * Stores the Square OAuth tokens for this professional.
     */
    public function connect(Request $request, SquareServiceSyncService $syncService)
    {
        $request->validate([
            'access_token'  => 'required|string',
            'refresh_token' => 'required|string',
            'merchant_id'   => 'required|string',
            'expires_at'    => 'required|string',
        ]);

        $pro = $this->currentProfessional($request);
        $merchantId = trim((string) $request->input('merchant_id'));
        if ($merchantId === '') {
            return $this->error('merchant_id is required.', 422);
        }

        $integration = ProfessionalIntegration::query()->updateOrCreate(
            [
                'professional_id' => $pro->id,
                'provider' => ProfessionalIntegration::PROVIDER_SQUARE,
            ],
            [
                'external_account_id' => $merchantId !== '' ? $merchantId : null,
                'access_token' => $request->input('access_token'),
                'refresh_token' => $request->input('refresh_token'),
                'expires_at' => $request->input('expires_at'),
                'last_catalog_sync_error' => null,
            ]
        );

        $syncQueued = false;
        $syncFallbackInline = false;

        // Pull initial services from Square right after connect.
        // Prefer async queue, but gracefully fallback to inline if queue infra is unavailable.
        try {
            SyncSquareCatalogDeltaJob::dispatch((string) $integration->external_account_id, null, true);
            $syncQueued = true;
        } catch (\Throwable $dispatchError) {
            Log::warning('Queue dispatch failed for initial Square sync; falling back inline', [
                'professional_id' => $pro->id,
                'merchant_id' => $integration->external_account_id,
                'message' => $dispatchError->getMessage(),
            ]);

            try {
                $syncService->syncFromSquare($pro, fullSync: true);
                $syncFallbackInline = true;
            } catch (\Throwable $syncError) {
                Log::warning('Initial inline Square sync failed after queue dispatch failure', [
                    'professional_id' => $pro->id,
                    'merchant_id' => $integration->external_account_id,
                    'message' => $syncError->getMessage(),
                ]);
            }
        }

        Log::info('Square connected', [
            'professional_id' => $pro->id,
            'merchant_id'     => $integration->external_account_id,
        ]);

        return $this->success([
            'connected'   => true,
            'merchant_id' => $integration->external_account_id,
            'expires_at'  => $integration->expires_at?->toIso8601String(),
            'sync_queued' => $syncQueued,
            'sync_fallback_inline' => $syncFallbackInline,
        ]);
    }

    /**
     * POST /api/square/disconnect
     * Clears the stored Square tokens for this professional.
     */
    public function disconnect(Request $request)
    {
        $pro = $this->currentProfessional($request);
        $pro->integrations()
            ->where('provider', ProfessionalIntegration::PROVIDER_SQUARE)
            ->delete();

        Log::info('Square disconnected', [
            'professional_id' => $pro->id,
        ]);

        return $this->success([
            'connected' => false,
        ]);
    }

    /**
     * GET /api/square/token
     * Returns Square integration status (token is kept server-side).
     */
    public function token(Request $request)
    {
        if ($error = $this->ensureSquareConnected($request)) {
            return $error;
        }

        $integration = $this->currentSquareIntegration($request);

        return $this->success([
            'connected'  => $integration?->access_token !== null,
            'expires_at' => $integration?->expires_at
                ? $integration->expires_at->toIso8601String()
                : null,
        ]);
    }

    /**
     * POST /api/square/services/sync
     * Runs a full pull from Square services into Commet immediately.
     * This endpoint is used by the manual refresh button and must work without queue workers.
     */
    public function syncServicesNow(Request $request, SquareServiceSyncService $syncService)
    {
        if ($error = $this->ensureSquareConnected($request)) {
            return $error;
        }

        $pro = $this->currentProfessional($request);
        $integration = $this->currentSquareIntegration($request);

        try {
            $stats = $syncService->syncFromSquare($pro, fullSync: true);
        } catch (SquareApiException $e) {
            [$message, $status] = $this->buildSquareErrorMessage($e);

            Log::warning('Manual Square sync failed (Square API)', [
                'professional_id' => $pro->id,
                'merchant_id' => $integration?->external_account_id,
                'status' => $e->status,
                'message' => $e->getMessage(),
                'payload' => $e->payload,
            ]);

            return $this->error($message, $status);
        } catch (\Throwable $e) {
            Log::warning('Manual Square sync failed', [
                'professional_id' => $pro->id,
                'merchant_id' => $integration?->external_account_id,
                'message' => $e->getMessage(),
            ]);

            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'queued' => false,
            'synced_inline' => true,
            'merchant_id' => $integration?->external_account_id,
            'synced' => $stats['synced'] ?? 0,
            'deleted' => $stats['deleted'] ?? 0,
            'latest_time' => $stats['latest_time'] ?? null,
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

        if ($error = $this->ensureSquareConnected($request)) {
            return $error;
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
