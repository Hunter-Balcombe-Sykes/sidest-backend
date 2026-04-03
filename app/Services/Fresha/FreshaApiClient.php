<?php

namespace App\Services\Fresha;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

// V2: Fresha Partner API client for services, bookings, and availability. Automatic token refresh on 401.
class FreshaApiClient
{
    public function __construct(
        private readonly FreshaTokenService $tokenService
    ) {}

    private function businessId(Professional $professional): string
    {
        $integration = $professional->integrationForProvider(ProfessionalIntegration::PROVIDER_FRESHA);
        $businessId = trim((string) ($integration?->external_account_id ?? ''));
        if ($businessId === '') {
            throw new FreshaApiException('Fresha business ID is missing.');
        }

        return $businessId;
    }

    /**
     * Fetch services from the Fresha business.
     *
     * NOTE: Update the endpoint path and response structure based on actual Fresha API docs.
     * This mirrors SquareApiClient::fetchAppointmentServiceVariations().
     *
     * @return array{services: array<int, array<string, mixed>>, latest_time: string|null}
     */
    public function fetchServices(Professional $professional, ?string $beginTime = null): array
    {
        $services = [];
        $latestTime = null;
        $cursor = null;

        do {
            $query = [];
            if ($beginTime) {
                $query['modified_since'] = $beginTime;
            }
            if ($cursor) {
                $query['cursor'] = $cursor;
            }

            // NOTE: Update endpoint path based on actual Fresha Partner API docs.
            $data = $this->request($professional, 'GET', '/v1/businesses/'.$this->businessId($professional).'/services', $query);

            $items = is_array($data['data'] ?? null) ? $data['data'] : (is_array($data['services'] ?? null) ? $data['services'] : []);

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                // NOTE: Map these fields based on actual Fresha API response structure.
                $services[] = [
                    'service_id' => (string) ($item['id'] ?? ''),
                    'variation_id' => (string) ($item['variation_id'] ?? $item['id'] ?? ''),
                    'item_name' => (string) ($item['name'] ?? ''),
                    'variation_name' => (string) ($item['variation_name'] ?? 'Regular'),
                    'item_description' => isset($item['description']) ? (string) $item['description'] : null,
                    'duration_minutes' => isset($item['duration']) ? (int) $item['duration'] : null,
                    'price_cents' => isset($item['price']) ? (int) $item['price'] : null,
                    'currency_code' => strtoupper((string) ($item['currency'] ?? '')),
                    'available_for_booking' => (bool) ($item['active'] ?? $item['available_for_booking'] ?? true),
                    'category_name' => (string) ($item['category']['name'] ?? $item['category_name'] ?? ''),
                    'category_id' => (string) ($item['category']['id'] ?? $item['category_id'] ?? ''),
                    'item_version' => isset($item['version']) ? (int) $item['version'] : null,
                    'deleted' => (bool) ($item['deleted'] ?? false),
                ];

                // Track latest timestamp for delta sync cursor
                $updatedAt = $item['updated_at'] ?? $item['modified_at'] ?? null;
                if ($updatedAt && (! $latestTime || $updatedAt > $latestTime)) {
                    $latestTime = $updatedAt;
                }
            }

            $cursor = $data['cursor'] ?? $data['meta']['cursor'] ?? null;
        } while ($cursor);

        return [
            'services' => $services,
            'latest_time' => $latestTime,
        ];
    }

    /**
     * Create a service in Fresha.
     *
     * NOTE: May require partner-level API access.
     */
    public function createService(Professional $professional, array $serviceData): array
    {
        return $this->request($professional, 'POST', '/v1/businesses/'.$this->businessId($professional).'/services', [], $serviceData);
    }

    /**
     * Update a service in Fresha.
     *
     * NOTE: May require partner-level API access.
     */
    public function updateService(Professional $professional, string $serviceId, array $serviceData): array
    {
        return $this->request($professional, 'PUT', '/v1/businesses/'.$this->businessId($professional).'/services/'.$serviceId, [], $serviceData);
    }

    /**
     * Delete a service in Fresha.
     */
    public function deleteService(Professional $professional, string $serviceId): void
    {
        $this->request($professional, 'DELETE', '/v1/businesses/'.$this->businessId($professional).'/services/'.$serviceId);
    }

    /**
     * Retrieve a single service by ID.
     */
    public function retrieveService(Professional $professional, string $serviceId): array
    {
        $response = $this->request($professional, 'GET', '/v1/businesses/'.$this->businessId($professional).'/services/'.$serviceId);

        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }

    /**
     * Get the Fresha business / location info.
     */
    public function getBusiness(Professional $professional): array
    {
        return $this->request($professional, 'GET', '/v1/businesses/'.$this->businessId($professional));
    }

    /**
     * Search availability for a service on a given date.
     *
     * NOTE: Update endpoint and request body based on actual Fresha API docs.
     */
    public function searchAvailability(Professional $professional, array $body): array
    {
        return $this->request($professional, 'POST', '/v1/businesses/'.$this->businessId($professional).'/availability/search', [], $body);
    }

    /**
     * Create a booking in Fresha.
     *
     * NOTE: Update endpoint and request body based on actual Fresha API docs.
     * Fresha does NOT support direct payment processing — only the booking is created.
     */
    public function createBooking(Professional $professional, array $body): array
    {
        return $this->request($professional, 'POST', '/v1/businesses/'.$this->businessId($professional).'/bookings', [], $body);
    }

    /**
     * Cancel a booking in Fresha.
     */
    public function cancelBooking(Professional $professional, string $bookingId): array
    {
        return $this->request($professional, 'POST', '/v1/businesses/'.$this->businessId($professional).'/bookings/'.$bookingId.'/cancel');
    }

    /**
     * Create or find a customer in Fresha.
     */
    public function createCustomer(Professional $professional, array $customerData): array
    {
        return $this->request($professional, 'POST', '/v1/businesses/'.$this->businessId($professional).'/customers', [], $customerData);
    }

    /**
     * Generic request method mirroring SquareApiClient::request().
     *
     * @param  array<string, string|int|float|bool|null>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    public function request(
        Professional $professional,
        string $method,
        string $path,
        array $query = [],
        ?array $body = null
    ): array {
        $token = $this->tokenService->getAccessToken($professional);

        $response = $this->makeRequest($token, $method, $path, $query, $body);

        // Access token might have been revoked/expired unexpectedly; refresh once.
        if ($response->status() === 401) {
            $token = $this->tokenService->refreshAccessToken($professional);
            $response = $this->makeRequest($token, $method, $path, $query, $body);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            $payload = [];
        }

        if (! $response->successful()) {
            $status = $response->status();
            $message = sprintf('Fresha API request failed: %s %s (HTTP %s)', $method, $path, $status);
            $errors = is_array($payload['errors'] ?? null) ? $payload['errors'] : null;
            if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
                $first = $errors[0];
                $detail = isset($first['detail']) && is_string($first['detail']) ? trim($first['detail']) : '';
                $code = isset($first['code']) && is_string($first['code']) ? trim($first['code']) : '';

                $parts = [];
                if ($code !== '') {
                    $parts[] = $code;
                }
                $meta = $parts ? sprintf(' [%s]', implode('/', $parts)) : '';
                $suffix = $detail !== '' ? sprintf(': %s', $detail) : '';
                $message = sprintf('Fresha API %s %s failed (HTTP %s)%s%s', strtoupper($method), $path, $status, $meta, $suffix);
            }

            throw new FreshaApiException($message, $status, $payload);
        }

        return $payload;
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function makeRequest(
        string $accessToken,
        string $method,
        string $path,
        array $query = [],
        ?array $body = null
    ): Response {
        $request = Http::acceptJson()
            ->asJson()
            ->timeout(30)
            ->withToken($accessToken);

        $url = $this->baseUrl().$path;

        return match (strtoupper($method)) {
            'GET' => $request->get($url, $query),
            'DELETE' => $request->delete($url, $query),
            'POST' => $request->post($url.($query ? '?'.http_build_query($query) : ''), $body ?? []),
            'PUT' => $request->put($url.($query ? '?'.http_build_query($query) : ''), $body ?? []),
            'PATCH' => $request->patch($url.($query ? '?'.http_build_query($query) : ''), $body ?? []),
            default => throw new FreshaApiException('Unsupported Fresha request method: '.$method),
        };
    }

    private function baseUrl(): string
    {
        $environment = strtolower((string) config('services.fresha.environment', 'production'));
        if ($environment === 'sandbox') {
            return 'https://partner-api-sandbox.fresha.com';
        }

        return 'https://partner-api.fresha.com';
    }
}
