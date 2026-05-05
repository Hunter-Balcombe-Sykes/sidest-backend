<?php

namespace App\Services\Fresha;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

// V2: Fresha OAuth2 token management with auto-refresh when within 5 minutes of expiration.
class FreshaTokenService
{
    public function getAccessToken(Professional $professional): string
    {
        $integration = $professional->integrationForProvider(ProfessionalIntegration::PROVIDER_FRESHA);
        if (! $integration) {
            throw new FreshaApiException('Fresha access token is missing.');
        }

        $token = trim((string) ($integration->access_token ?? ''));
        if ($token === '') {
            throw new FreshaApiException('Fresha access token is missing.');
        }

        $expiresAt = $integration->expires_at;
        if ($expiresAt && $expiresAt->greaterThan(now()->addMinutes(5))) {
            return $token;
        }

        return $this->refreshAccessToken($professional);
    }

    public function refreshAccessToken(Professional $professional): string
    {
        $integration = $professional->integrationForProvider(ProfessionalIntegration::PROVIDER_FRESHA);
        if (! $integration) {
            throw new FreshaApiException('Fresha refresh token is missing.');
        }

        // Single-flight lock: only one concurrent caller performs the HTTP refresh.
        // Others wait up to 10s, then re-read the row (which should now be fresh).
        $lock = Cache::lock('integration_refresh:'.$integration->id, 30);

        try {
            $lock->block(10);
        } catch (LockTimeoutException $e) {
            // Timed out waiting for the lock — re-read and return if another process already refreshed.
            $integration->refresh();
            $expiresAt = $integration->expires_at;
            if ($expiresAt && $expiresAt->greaterThan(now()->addMinutes(5))) {
                $token = trim((string) ($integration->access_token ?? ''));
                if ($token !== '') {
                    return $token;
                }
            }
            throw $e;
        }

        try {
            // Double-check after acquiring the lock: a concurrent caller may have just refreshed.
            $integration->refresh();
            $expiresAt = $integration->expires_at;
            if ($expiresAt && $expiresAt->greaterThan(now()->addMinutes(5))) {
                $token = trim((string) ($integration->access_token ?? ''));
                if ($token !== '') {
                    return $token;
                }
            }

            $refreshToken = trim((string) ($integration->refresh_token ?? ''));
            if ($refreshToken === '') {
                throw new FreshaApiException('Fresha refresh token is missing.');
            }

            $clientId = trim((string) config('services.fresha.client_id', ''));
            $clientSecret = trim((string) config('services.fresha.client_secret', ''));

            if ($clientId === '' || $clientSecret === '') {
                throw new FreshaApiException('Fresha OAuth credentials are not configured.');
            }

            // NOTE: Update this endpoint based on actual Fresha Partner API documentation.
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(20)
                ->post($this->baseUrl().'/oauth2/token', [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);

            $payload = $response->json();
            if (! $response->successful() || ! is_array($payload)) {
                throw new FreshaApiException(
                    sprintf('Failed to refresh Fresha token (HTTP %s).', $response->status()),
                    $response->status(),
                    is_array($payload) ? $payload : null
                );
            }

            $accessToken = trim((string) ($payload['access_token'] ?? ''));
            if ($accessToken === '') {
                throw new FreshaApiException('Fresha token refresh response did not include access_token.');
            }

            $integration->access_token = $accessToken;
            $integration->refresh_token = $payload['refresh_token'] ?? $integration->refresh_token;
            $integration->expires_at = isset($payload['expires_at'])
                ? CarbonImmutable::parse((string) $payload['expires_at'])
                : null;
            $integration->save();

            return $accessToken;
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Lock already expired or driver doesn't support release; ignore.
            }
        }
    }

    /**
     * Attempt to revoke the access token at Fresha. Callers catch and log — deletion must not be blocked.
     * NOTE: Fresha revoke endpoint is inferred from OAuth2 conventions (RFC 7009); verify against Partner API docs.
     */
    public function revokeToken(ProfessionalIntegration $integration): void
    {
        $clientId = trim((string) config('services.fresha.client_id', ''));
        $clientSecret = trim((string) config('services.fresha.client_secret', ''));
        $accessToken = trim((string) ($integration->access_token ?? ''));

        if ($clientId === '' || $clientSecret === '' || $accessToken === '') {
            return;
        }

        Http::acceptJson()
            ->asJson()
            ->timeout(10)
            ->post($this->baseUrl().'/oauth2/revoke', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'token' => $accessToken,
            ]);
    }

    private function baseUrl(): string
    {
        $environment = strtolower((string) config('services.fresha.environment', 'production'));
        if ($environment === 'sandbox') {
            // NOTE: Update with actual Fresha sandbox URL when confirmed.
            return 'https://partner-api-sandbox.fresha.com';
        }

        // NOTE: Update with actual Fresha production API URL when confirmed.
        return 'https://partner-api.fresha.com';
    }
}
