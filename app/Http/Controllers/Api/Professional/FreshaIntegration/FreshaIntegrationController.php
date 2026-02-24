<?php

namespace App\Http\Controllers\Api\Professional\FreshaIntegration;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Jobs\Fresha\SyncFreshaCatalogDeltaJob;
use App\Models\Core\Professional\Service;
use App\Services\Fresha\FreshaApiException;
use App\Services\Fresha\FreshaServiceSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FreshaIntegrationController extends ApiController
{
    use ResolveCurrentProfessional;

    /**
     * Check if the professional has Fresha connected.
     */
    private function ensureFreshaConnected(Request $request): JsonResponse|null
    {
        $pro = $this->currentProfessional($request);

        if (empty($pro->fresha_access_token) || empty($pro->fresha_business_id)) {
            return $this->error('Fresha account not connected.', 404);
        }

        return null; // Success: connection exists
    }

    /**
     * Build user-friendly error message from Fresha API exception.
     */
    private function buildFreshaErrorMessage(FreshaApiException $e): array
    {
        $rawMessage = trim($e->getMessage());
        $message = $rawMessage !== '' ? $rawMessage : 'Fresha sync failed.';
        $lower = strtolower($message);
        $status = $e->status > 0 ? $e->status : 422;

        $shouldSuggestReconnect =
            str_contains($lower, 'resource not found') ||
            str_contains($lower, 'unauthorized') ||
            str_contains($lower, 'access token') ||
            str_contains($lower, 'business');

        if ($shouldSuggestReconnect) {
            $message .= ' Please reconnect your Fresha account. If this persists, verify FRESHA_ENVIRONMENT matches the account mode (production vs sandbox).';
            $status = 409;
        }

        return [$message, $status];
    }

    /**
     * GET /api/fresha/status
     * Returns whether the user has Fresha connected and when it expires.
     */
    public function status(Request $request)
    {
        $pro = $this->currentProfessional($request);

        $connected = !empty($pro->fresha_access_token) && !empty($pro->fresha_business_id);

        return $this->success([
            'connected' => $connected,
            'business_id' => $connected ? $pro->fresha_business_id : null,
            'expires_at' => $connected && $pro->fresha_expires_at
                ? $pro->fresha_expires_at->toIso8601String()
                : null,
        ]);
    }

    /**
     * POST /api/fresha/connect
     * Stores the Fresha OAuth tokens for this professional.
     */
    public function connect(Request $request, FreshaServiceSyncService $syncService)
    {
        $request->validate([
            'access_token'  => 'required|string',
            'refresh_token' => 'required|string',
            'business_id'   => 'required|string',
            'expires_at'    => 'required|string',
        ]);

        $pro = $this->currentProfessional($request);

        $pro->fresha_access_token  = $request->input('access_token');
        $pro->fresha_refresh_token = $request->input('refresh_token');
        $pro->fresha_business_id   = $request->input('business_id');
        $pro->fresha_expires_at    = $request->input('expires_at');
        $pro->fresha_last_catalog_sync_error = null;
        $pro->save();

        $syncQueued = false;
        $syncFallbackInline = false;

        // Pull initial services from Fresha right after connect.
        // Prefer async queue, but gracefully fallback to inline if queue infra is unavailable.
        try {
            SyncFreshaCatalogDeltaJob::dispatch($pro->fresha_business_id, null, true);
            $syncQueued = true;
        } catch (\Throwable $dispatchError) {
            Log::warning('Queue dispatch failed for initial Fresha sync; falling back inline', [
                'professional_id' => $pro->id,
                'business_id' => $pro->fresha_business_id,
                'message' => $dispatchError->getMessage(),
            ]);

            try {
                $syncService->syncFromFresha($pro, fullSync: true);
                $syncFallbackInline = true;
            } catch (\Throwable $syncError) {
                Log::warning('Inline Fresha sync also failed during connect', [
                    'professional_id' => $pro->id,
                    'message' => $syncError->getMessage(),
                ]);
            }
        }

        Log::info('Fresha connected', [
            'professional_id' => $pro->id,
            'business_id' => $pro->fresha_business_id,
        ]);

        return $this->success([
            'connected' => true,
            'business_id' => $pro->fresha_business_id,
            'expires_at' => $pro->fresha_expires_at
                ? $pro->fresha_expires_at->toIso8601String()
                : null,
            'sync_queued' => $syncQueued,
            'sync_fallback_inline' => $syncFallbackInline,
        ]);
    }

    /**
     * POST /api/fresha/disconnect
     * Clears the stored Fresha tokens for this professional.
     */
    public function disconnect(Request $request)
    {
        $pro = $this->currentProfessional($request);

        $pro->fresha_access_token  = null;
        $pro->fresha_refresh_token = null;
        $pro->fresha_business_id   = null;
        $pro->fresha_expires_at    = null;
        $pro->fresha_catalog_latest_time = null;
        $pro->fresha_last_catalog_sync_at = null;
        $pro->fresha_last_catalog_sync_error = null;
        $pro->save();

        Log::info('Fresha disconnected', [
            'professional_id' => $pro->id,
        ]);

        return $this->success([
            'connected' => false,
        ]);
    }

    /**
     * GET /api/fresha/token
     * Returns the decrypted Fresha access token for frontend use.
     */
    public function token(Request $request)
    {
        if ($error = $this->ensureFreshaConnected($request)) {
            return $error;
        }

        $pro = $this->currentProfessional($request);

        return $this->success([
            'access_token' => $pro->fresha_access_token,
            'expires_at'   => $pro->fresha_expires_at
                ? $pro->fresha_expires_at->toIso8601String()
                : null,
        ]);
    }

    /**
     * POST /api/fresha/services/sync
     * Runs a full pull from Fresha services into Commet immediately.
     */
    public function syncServicesNow(Request $request, FreshaServiceSyncService $syncService)
    {
        if ($error = $this->ensureFreshaConnected($request)) {
            return $error;
        }

        $pro = $this->currentProfessional($request);

        try {
            $stats = $syncService->syncFromFresha($pro, fullSync: true);
        } catch (FreshaApiException $e) {
            [$message, $status] = $this->buildFreshaErrorMessage($e);

            return $this->error($message, $status);
        }

        return $this->success([
            'queued' => false,
            'synced_inline' => true,
            'business_id' => $pro->fresha_business_id,
            'synced' => $stats['synced'] ?? 0,
            'deleted' => $stats['deleted'] ?? 0,
            'latest_time' => $stats['latest_time'] ?? null,
        ]);
    }

    /**
     * POST /api/fresha/services/{service}/push
     * Pushes one local service update to Fresha immediately.
     */
    public function pushServiceNow(Request $request, Service $service, FreshaServiceSyncService $syncService)
    {
        $pro = $this->currentProfessional($request);

        abort_unless($service->professional_id === $pro->id, 404);

        if ($error = $this->ensureFreshaConnected($request)) {
            return $error;
        }

        try {
            $syncService->pushServiceToFresha($service, 'upsert');
        } catch (\Throwable $e) {
            Log::warning('Manual Fresha push failed', [
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
            'fresha_service_id' => $fresh?->fresha_service_id,
            'fresha_variation_id' => $fresh?->fresha_variation_id,
            'fresha_last_synced_at' => $fresh?->fresha_last_synced_at?->toIso8601String(),
            'fresha_sync_error' => $fresh?->fresha_sync_error,
        ]);
    }
}
