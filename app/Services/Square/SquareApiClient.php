<?php

namespace App\Services\Square;

use App\Models\Core\Professional\Professional;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// V2: Square Catalog API client for booking services. Handles pagination, category mapping, and automatic token refresh on 401.
class SquareApiClient
{
    public function __construct(
        private readonly SquareTokenService $tokenService
    ) {}

    /**
     * @return array{services: array<int, array<string, mixed>>, latest_time: string|null}
     */
    public function fetchAppointmentServiceVariations(Professional $professional, ?string $beginTime = null): array
    {
        $services = [];
        $categoryNamesById = [];
        $latestTime = null;
        $cursor = null;

        do {
            $body = [
                'object_types' => ['ITEM', 'CATEGORY'],
                'include_deleted_objects' => true,
            ];

            if ($beginTime) {
                $body['begin_time'] = $beginTime;
            }
            if ($cursor) {
                $body['cursor'] = $cursor;
            }

            $data = $this->request($professional, 'POST', '/v2/catalog/search', [], $body);
            $objects = is_array($data['objects'] ?? null) ? $data['objects'] : [];
            $latestTime = is_string($data['latest_time'] ?? null) ? $data['latest_time'] : $latestTime;
            $cursor = is_string($data['cursor'] ?? null) ? $data['cursor'] : null;

            foreach ($objects as $object) {
                if (! is_array($object)) {
                    continue;
                }

                $type = (string) ($object['type'] ?? '');
                if ($type === 'CATEGORY') {
                    $categoryId = (string) ($object['id'] ?? '');
                    if ($categoryId === '') {
                        continue;
                    }

                    $categoryDeleted = (bool) ($object['is_deleted'] ?? false);
                    if ($categoryDeleted) {
                        unset($categoryNamesById[$categoryId]);

                        continue;
                    }

                    $categoryData = is_array($object['category_data'] ?? null) ? $object['category_data'] : [];
                    $categoryName = trim((string) ($categoryData['name'] ?? ''));
                    if ($categoryName !== '') {
                        $categoryNamesById[$categoryId] = $categoryName;
                    }

                    continue;
                }

                if ($type !== 'ITEM') {
                    continue;
                }

                $itemId = (string) ($object['id'] ?? '');
                if ($itemId === '') {
                    continue;
                }

                $itemVersion = isset($object['version']) ? (int) $object['version'] : null;
                $itemDeleted = (bool) ($object['is_deleted'] ?? false);

                if ($itemDeleted) {
                    $services[] = [
                        'item_id' => $itemId,
                        'item_version' => $itemVersion,
                        'deleted' => true,
                    ];

                    continue;
                }

                $itemData = is_array($object['item_data'] ?? null) ? $object['item_data'] : [];
                $productType = strtoupper((string) ($itemData['product_type'] ?? ''));
                if ($productType !== 'APPOINTMENTS_SERVICE') {
                    continue;
                }

                $categoryId = trim((string) ($itemData['category_id'] ?? ''));
                if ($categoryId === '') {
                    $categories = is_array($itemData['categories'] ?? null) ? $itemData['categories'] : [];
                    $firstCategory = $categories[0] ?? null;
                    if (is_array($firstCategory)) {
                        $categoryId = trim((string) ($firstCategory['id'] ?? ''));
                    }
                }
                if ($categoryId === '') {
                    $reportingCategory = is_array($itemData['reporting_category'] ?? null) ? $itemData['reporting_category'] : null;
                    if (is_array($reportingCategory)) {
                        $categoryId = trim((string) ($reportingCategory['id'] ?? ''));
                    }
                }

                $variations = is_array($itemData['variations'] ?? null) ? $itemData['variations'] : [];
                foreach ($variations as $variation) {
                    if (! is_array($variation)) {
                        continue;
                    }

                    $variationId = trim((string) ($variation['id'] ?? ''));
                    if ($variationId === '') {
                        continue;
                    }

                    $variationDeleted = (bool) ($variation['is_deleted'] ?? false);
                    if ($variationDeleted) {
                        $services[] = [
                            'item_id' => $itemId,
                            'variation_id' => $variationId,
                            'item_version' => $itemVersion,
                            'deleted' => true,
                        ];

                        continue;
                    }

                    $variationData = is_array($variation['item_variation_data'] ?? null) ? $variation['item_variation_data'] : [];
                    $durationMs = isset($variationData['service_duration']) ? (int) $variationData['service_duration'] : null;
                    $price = is_array($variationData['price_money'] ?? null) ? $variationData['price_money'] : [];

                    $services[] = [
                        'item_id' => $itemId,
                        'item_name' => (string) ($itemData['name'] ?? 'Service'),
                        'item_description' => isset($itemData['description']) ? (string) $itemData['description'] : null,
                        'item_version' => $itemVersion,
                        'variation_id' => $variationId,
                        'variation_name' => (string) ($variationData['name'] ?? ''),
                        'duration_minutes' => $durationMs !== null ? (int) round($durationMs / 60000) : null,
                        'price_cents' => isset($price['amount']) ? (int) $price['amount'] : null,
                        'currency_code' => isset($price['currency']) ? (string) $price['currency'] : null,
                        'available_for_booking' => (bool) ($variationData['available_for_booking'] ?? false),
                        'square_category_id' => $categoryId !== '' ? $categoryId : null,
                        'deleted' => false,
                    ];
                }
            }
        } while ($cursor !== null);

        foreach ($services as &$service) {
            if (! is_array($service) || (bool) ($service['deleted'] ?? false)) {
                continue;
            }
            $rowCategoryId = trim((string) ($service['square_category_id'] ?? ''));
            $service['square_category_name'] = $rowCategoryId !== ''
                ? ($categoryNamesById[$rowCategoryId] ?? null)
                : null;
        }
        unset($service);

        return [
            'services' => $services,
            'latest_time' => $latestTime,
        ];
    }

    /**
     * @param  array<string, mixed>  $catalogObject
     * @return array<string, mixed>
     */
    public function upsertCatalogObject(Professional $professional, array $catalogObject): array
    {
        $response = $this->request($professional, 'POST', '/v2/catalog/object', [], [
            'idempotency_key' => (string) Str::uuid(),
            'object' => $catalogObject,
        ]);

        return is_array($response['catalog_object'] ?? null) ? $response['catalog_object'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveCatalogObject(Professional $professional, string $objectId): array
    {
        $response = $this->request($professional, 'GET', '/v2/catalog/object/'.$objectId);

        return is_array($response['object'] ?? null) ? $response['object'] : [];
    }

    public function deleteCatalogObject(Professional $professional, string $objectId): void
    {
        $this->request($professional, 'DELETE', '/v2/catalog/object/'.$objectId);
    }

    /**
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
        $attempt = 0;
        $maxRetries = 3;

        while (true) {
            $response = $this->makeRequest($token, $method, $path, $query, $body);

            if ($response->status() === 429 && $attempt < $maxRetries) {
                $wait = max(1000, ((int) ($response->header('Retry-After') ?? 1)) * 1000);
                usleep($wait * 1000);
                $attempt++;
                continue;
            }

            break;
        }

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
            $message = sprintf('Square API request failed: %s %s (HTTP %s)', $method, $path, $status);
            $errors = is_array($payload['errors'] ?? null) ? $payload['errors'] : null;
            if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
                $first = $errors[0];
                $detail = isset($first['detail']) && is_string($first['detail']) ? trim($first['detail']) : '';
                $code = isset($first['code']) && is_string($first['code']) ? trim($first['code']) : '';
                $category = isset($first['category']) && is_string($first['category']) ? trim($first['category']) : '';

                $parts = [];
                if ($code !== '') {
                    $parts[] = $code;
                }
                if ($category !== '') {
                    $parts[] = $category;
                }
                $meta = $parts ? sprintf(' [%s]', implode('/', $parts)) : '';
                $suffix = $detail !== '' ? sprintf(': %s', $detail) : '';
                $message = sprintf('Square API %s %s failed (HTTP %s)%s%s', strtoupper($method), $path, $status, $meta, $suffix);
            }

            throw new SquareApiException($message, $status, $payload);
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
            ->withToken($accessToken)
            ->withHeaders([
                'Square-Version' => '2025-10-16',
            ]);

        $url = $this->baseUrl().$path;

        return match (strtoupper($method)) {
            'GET' => $request->get($url, $query),
            'DELETE' => $request->delete($url, $query),
            'POST' => $request->post($url.($query ? '?'.http_build_query($query) : ''), $body ?? []),
            'PUT' => $request->put($url.($query ? '?'.http_build_query($query) : ''), $body ?? []),
            'PATCH' => $request->patch($url.($query ? '?'.http_build_query($query) : ''), $body ?? []),
            default => throw new SquareApiException('Unsupported Square request method: '.$method),
        };
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
