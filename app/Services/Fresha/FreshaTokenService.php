<?php

namespace App\Services\Fresha;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

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
