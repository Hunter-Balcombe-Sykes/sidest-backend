<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Jobs\Fresha\SyncFreshaCatalogDeltaJob;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Fresha\FreshaServiceSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FreshaCatalogWebhookController extends ApiController
{
    public function __invoke(Request $request, FreshaServiceSyncService $syncService): JsonResponse
    {
        $rawBody = $request->getContent() ?: '';
        $signature = (string) $request->header('x-fresha-signature', '');

        if (! $this->isValidSignature($request, $rawBody, $signature)) {
            Log::warning('Fresha webhook rejected: invalid signature', [
                'ip' => $request->ip(),
                'event_type' => $request->input('type'),
            ]);

            return $this->error('Invalid Fresha webhook signature.', 401);
        }

        $payload = $request->json()->all();

        // NOTE: Update these field names based on actual Fresha webhook payload structure.
        $eventType = trim((string) ($payload['type'] ?? $payload['event_type'] ?? ''));
        $businessId = trim((string) ($payload['business_id'] ?? data_get($payload, 'data.business_id', '')));

        // Deduplicate — Cache::add() is atomic: it sets the key only if absent and
        // returns false when the key already exists, eliminating the has()+put() race.
        $eventId = trim((string) ($payload['event_id'] ?? $payload['id'] ?? ''));
        if ($eventId !== '') {
            $cacheKey = 'fresha_webhook:' . $eventId;
            if (! Cache::add($cacheKey, true, now()->addHours(24))) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }

        Log::info('Fresha webhook received', [
            'event_type' => $eventType,
            'business_id' => $businessId,
        ]);

        // Handle authorization revoked
        if (in_array($eventType, ['oauth.authorization.revoked', 'authorization.revoked'], true)) {
            if ($businessId !== '') {
                ProfessionalIntegration::query()
                    ->where('provider', ProfessionalIntegration::PROVIDER_FRESHA)
                    ->where('external_account_id', $businessId)
                    ->delete();
            }

            return $this->success(['received' => true, 'revoked' => true]);
        }

        // Handle catalog/service updates
        if (! in_array($eventType, ['catalog.version.updated', 'service.updated', 'service.created', 'service.deleted'], true)) {
            return $this->success(['received' => true, 'ignored' => $eventType !== '' ? $eventType : 'unknown_event']);
        }

        if ($businessId === '') {
            return $this->success(['received' => true, 'ignored' => 'no_business_id']);
        }

        try {
            SyncFreshaCatalogDeltaJob::dispatch($businessId, null, false);

            return $this->success(['received' => true, 'queued' => true]);
        } catch (\Throwable $dispatchError) {
            Log::warning('Fresha webhook queue dispatch failed; attempting inline sync', [
                'business_id' => $businessId,
                'message' => $dispatchError->getMessage(),
            ]);

            $integration = ProfessionalIntegration::query()
                ->where('provider', ProfessionalIntegration::PROVIDER_FRESHA)
                ->where('external_account_id', $businessId)
                ->first();

            $professional = $integration
                ? Professional::query()->find($integration->professional_id)
                : null;

            if (! $professional) {
                return $this->success([
                    'received' => true,
                    'queued' => false,
                    'ignored' => 'business_not_found',
                ]);
            }

            try {
                $stats = $syncService->syncFromFresha($professional, fullSync: false);

                return $this->success([
                    'received' => true,
                    'queued' => false,
                    'synced_inline' => true,
                    'synced' => $stats['synced'] ?? 0,
                    'deleted' => $stats['deleted'] ?? 0,
                    'latest_time' => $stats['latest_time'] ?? null,
                ]);
            } catch (\Throwable $syncError) {
                Log::warning('Fresha webhook inline sync failed', [
                    'business_id' => $businessId,
                    'message' => $syncError->getMessage(),
                ]);

                // Return 200 to prevent noisy webhook retries; error is logged for investigation.
                return $this->success([
                    'received' => true,
                    'queued' => false,
                    'synced_inline' => false,
                    'error' => 'Inline sync failed.',
                ]);
            }
        }
    }

    /**
     * Validate the webhook signature from Fresha.
     *
     * NOTE: Update this method based on Fresha's actual webhook signature mechanism.
     * Currently mirrors the Square HMAC-SHA256 pattern.
     */
    private function isValidSignature(Request $request, string $rawBody, string $signature): bool
    {
        $signatureKey = trim((string) config('services.fresha.webhook_signature_key', ''));
        $notificationUrl = trim((string) config('services.fresha.webhook_notification_url', ''));

        if ($signatureKey === '' || $notificationUrl === '') {
            Log::warning('Fresha webhook signature key or notification URL not configured — rejecting webhook');

            return false;
        }

        if ($signature === '') {
            return false;
        }

        // NOTE: Update this hashing logic based on actual Fresha docs.
        // This mirrors Square's approach: HMAC-SHA256 of (notification_url + raw_body) with the signature key.
        $expectedSignature = base64_encode(
            hash_hmac('sha256', $notificationUrl . $rawBody, $signatureKey, true)
        );

        return hash_equals($expectedSignature, $signature);
    }
}
