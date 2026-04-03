<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Jobs\Square\SyncSquareCatalogDeltaJob;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Square\SquareServiceSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// V2: Square catalog webhook — validates HMAC, deduplicates events, triggers service sync. Booking integration only.
class SquareCatalogWebhookController extends ApiController
{
    public function __invoke(Request $request, SquareServiceSyncService $syncService): JsonResponse
    {
        $rawBody = $request->getContent() ?: '';
        $signature = (string) $request->header('x-square-hmacsha256-signature', '');

        if (! $this->isValidSignature($request, $rawBody, $signature)) {
            Log::warning('Square webhook rejected: invalid signature', [
                'ip' => $request->ip(),
                'event_type' => $request->input('type'),
            ]);

            return $this->error('Invalid Square webhook signature.', 401);
        }

        $payload = $request->json()->all();
        if (! is_array($payload)) {
            return $this->success(['received' => true, 'ignored' => 'invalid_payload']);
        }

        $eventId = trim((string) ($payload['event_id'] ?? ''));
        if ($eventId !== '') {
            $dedupeKey = 'square:webhook:event:'.$eventId;
            if (! Cache::add($dedupeKey, true, now()->addHours(24))) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }

        $eventType = trim((string) ($payload['type'] ?? ''));
        $merchantId = trim((string) ($payload['merchant_id'] ?? ''));
        if ($merchantId === '') {
            return $this->success(['received' => true, 'ignored' => 'missing_merchant_id']);
        }

        if ($eventType === 'oauth.authorization.revoked') {
            ProfessionalIntegration::query()
                ->where('provider', ProfessionalIntegration::PROVIDER_SQUARE)
                ->where('external_account_id', $merchantId)
                ->delete();

            return $this->success(['received' => true, 'revoked' => true]);
        }

        if ($eventType !== 'catalog.version.updated') {
            return $this->success(['received' => true, 'ignored' => $eventType !== '' ? $eventType : 'unknown_event']);
        }

        try {
            SyncSquareCatalogDeltaJob::dispatch($merchantId, null, false);

            return $this->success(['received' => true, 'queued' => true]);
        } catch (\Throwable $dispatchError) {
            Log::warning('Square webhook queue dispatch failed; attempting inline sync', [
                'merchant_id' => $merchantId,
                'message' => $dispatchError->getMessage(),
            ]);

            $integration = ProfessionalIntegration::query()
                ->where('provider', ProfessionalIntegration::PROVIDER_SQUARE)
                ->where('external_account_id', $merchantId)
                ->first();

            $professional = $integration
                ? Professional::query()->find($integration->professional_id)
                : null;

            if (! $professional) {
                return $this->success([
                    'received' => true,
                    'queued' => false,
                    'ignored' => 'merchant_not_found',
                ]);
            }

            try {
                $stats = $syncService->syncFromSquare($professional, fullSync: false);

                return $this->success([
                    'received' => true,
                    'queued' => false,
                    'synced_inline' => true,
                    'synced' => $stats['synced'] ?? 0,
                    'deleted' => $stats['deleted'] ?? 0,
                    'latest_time' => $stats['latest_time'] ?? null,
                ]);
            } catch (\Throwable $syncError) {
                Log::warning('Square webhook inline sync failed', [
                    'merchant_id' => $merchantId,
                    'message' => $syncError->getMessage(),
                ]);

                // Return 200 to prevent noisy webhook retries; error is logged for investigation.
                return $this->success([
                    'received' => true,
                    'queued' => false,
                    'synced_inline' => false,
                ]);
            }
        }
    }

    private function isValidSignature(Request $request, string $body, string $signature): bool
    {
        $key = trim((string) config('services.square.webhook_signature_key', ''));
        if ($key === '' || $signature === '') {
            return false;
        }

        $configuredUrl = trim((string) config('services.square.webhook_notification_url', ''));
        $requestUrl = $request->fullUrl();

        $candidateUrls = array_values(array_unique(array_filter([
            $configuredUrl !== '' ? $configuredUrl : null,
            $requestUrl,
            str_ends_with($requestUrl, '/catalog') ? substr($requestUrl, 0, -8) : null,
            str_ends_with($requestUrl, '/catalog') ? null : rtrim($requestUrl, '/').'/catalog',
            $configuredUrl !== '' && str_ends_with($configuredUrl, '/catalog') ? substr($configuredUrl, 0, -8) : null,
            $configuredUrl !== '' && !str_ends_with($configuredUrl, '/catalog') ? rtrim($configuredUrl, '/').'/catalog' : null,
        ])));

        foreach ($candidateUrls as $notificationUrl) {
            $expected = base64_encode(hash_hmac('sha256', $notificationUrl.$body, $key, true));
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
