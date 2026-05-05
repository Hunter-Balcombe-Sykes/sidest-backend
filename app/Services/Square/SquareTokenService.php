<?php

namespace App\Services\Square;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

// V2: Square OAuth2 token management with auto-refresh when within 5 minutes of expiration.
class SquareTokenService
{
    public function getAccessToken(Professional $professional): string
    {
        $integration = $professional->integrationForProvider(ProfessionalIntegration::PROVIDER_SQUARE);
        if (! $integration) {
            throw new SquareApiException('Square access token is missing.');
        }

        $token = trim((string) ($integration->access_token ?? ''));
        if ($token === '') {
            throw new SquareApiException('Square access token is missing.');
        }

        $expiresAt = $integration->expires_at;
        if ($expiresAt && $expiresAt->greaterThan(now()->addMinutes(5))) {
            return $token;
        }

        return $this->refreshAccessToken($professional);
    }

    public function refreshAccessToken(Professional $professional): string
    {
        $integration = $professional->integrationForProvider(ProfessionalIntegration::PROVIDER_SQUARE);
        if (! $integration) {
            throw new SquareApiException('Square refresh token is missing.');
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
                throw new SquareApiException('Square refresh token is missing.');
            }

            $clientId = trim((string) config('services.square.application_id', ''));
            $clientSecret = trim((string) config('services.square.client_secret', ''));

            if ($clientId === '' || $clientSecret === '') {
                throw new SquareApiException('Square OAuth credentials are not configured.');
            }

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
                throw new SquareApiException(
                    sprintf('Failed to refresh Square token (HTTP %s).', $response->status()),
                    $response->status(),
                    is_array($payload) ? $payload : null
                );
            }

            $accessToken = trim((string) ($payload['access_token'] ?? ''));
            if ($accessToken === '') {
                throw new SquareApiException('Square token refresh response did not include access_token.');
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
     * Revoke the access token at Square. Callers catch and log — deletion must not be blocked.
     * Square revoke requires "Authorization: Client {secret}" rather than a bearer token.
     */
    public function revokeToken(ProfessionalIntegration $integration): void
    {
        $clientId = trim((string) config('services.square.application_id', ''));
        $clientSecret = trim((string) config('services.square.client_secret', ''));
        $accessToken = trim((string) ($integration->access_token ?? ''));

        if ($clientId === '' || $clientSecret === '' || $accessToken === '') {
            return;
        }

        Http::acceptJson()
            ->asJson()
            ->timeout(10)
            ->withHeaders(['Authorization' => 'Client '.$clientSecret])
            ->post($this->baseUrl().'/oauth2/revoke', [
                'client_id' => $clientId,
                'access_token' => $accessToken,
            ]);
    }

    private function baseUrl(): string
    {
        $environment = strtolower((string) config('services.square.environment', 'production'));
        if ($environment === 'sandbox') {
            return 'https://connect.squareupsandbox.com';
        }

        return 'https://connect.squareup.com';
    }
}
