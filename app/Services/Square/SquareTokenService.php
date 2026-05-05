<?php

namespace App\Services\Square;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

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
