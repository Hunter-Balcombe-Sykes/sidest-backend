<?php

namespace App\Services\Square;

use App\Models\Core\Professional\Professional;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

class SquareTokenService
{
    public function getAccessToken(Professional $professional): string
    {
        $token = trim((string) ($professional->square_access_token ?? ''));
        if ($token === '') {
            throw new SquareApiException('Square access token is missing.');
        }

        $expiresAt = $professional->square_expires_at;
        if ($expiresAt && $expiresAt->greaterThan(now()->addMinutes(5))) {
            return $token;
        }

        return $this->refreshAccessToken($professional);
    }

    public function refreshAccessToken(Professional $professional): string
    {
        $refreshToken = trim((string) ($professional->square_refresh_token ?? ''));
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

        $professional->square_access_token = $accessToken;
        $professional->square_refresh_token = $payload['refresh_token'] ?? $professional->square_refresh_token;
        $professional->square_expires_at = isset($payload['expires_at'])
            ? CarbonImmutable::parse((string) $payload['expires_at'])
            : null;
        $professional->save();

        return $accessToken;
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

