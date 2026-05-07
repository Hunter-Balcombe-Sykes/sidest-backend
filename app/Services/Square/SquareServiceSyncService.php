<?php

namespace App\Services\Square;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Professional\Service;
use App\Models\Core\Professional\ServiceCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Bidirectional service sync between Square and Partna. Booking integration — not V2 commerce.
class SquareServiceSyncService
{
    public function __construct(
        private readonly SquareApiClient $squareApiClient
    ) {}

    /**
     * Pull services from Square and upsert into Partna.
     *
     * @return array{synced:int, deleted:int, latest_time:string|null}
     */
    public function syncFromSquare(Professional $professional, bool $fullSync = false, ?string $beginTimeOverride = null): array
    {
        $integration = $professional->integrationForProvider(ProfessionalIntegration::PROVIDER_SQUARE);
        if (! $integration || empty($integration->access_token) || empty($integration->external_account_id)) {
            return ['synced' => 0, 'deleted' => 0, 'latest_time' => null];
        }

        $beginTime = $beginTimeOverride;
        if ($beginTime === null && ! $fullSync && $integration->catalog_latest_time) {
            $beginTime = CarbonImmutable::parse($integration->catalog_latest_time)->toIso8601String();
        }

        try {
            $fetched = $this->squareApiClient->fetchAppointmentServiceVariations($professional, $beginTime);
            $stats = $this->applySquareSnapshot($professional, $fetched['services'] ?? [], $fullSync);

            $integration->catalog_latest_time = isset($fetched['latest_time']) && is_string($fetched['latest_time'])
                ? CarbonImmutable::parse($fetched['latest_time'])
                : now();
            $integration->last_catalog_sync_at = now();
            $integration->last_catalog_sync_error = null;
            $integration->save();

            return [
                'synced' => $stats['synced'],
                'deleted' => $stats['deleted'],
                'latest_time' => $fetched['latest_time'] ?? null,
            ];
        } catch (\Throwable $e) {
            $integration->last_catalog_sync_error = mb_substr($e->getMessage(), 0, 2000);
            $integration->last_catalog_sync_at = now();
            $integration->save();
            throw $e;
        }
    }

    /**
     * Push one Partna service mutation to Square.
     */
    public function pushServiceToSquare(Service $service, string $action = 'upsert'): void
    {
        $professional = Professional::query()->find($service->professional_id);
        if (! $professional) {
            return;
        }
        $integration = $professional->integrationForProvider(ProfessionalIntegration::PROVIDER_SQUARE);
        if (! $integration || empty($integration->access_token) || empty($integration->external_account_id)) {
            return;
        }

        // Respect "smart sync" toggle when present.
        $smartSyncEnabled = (bool) data_get($professional->site?->settings, 'services_auto_sync_enabled', false);
        if (! $smartSyncEnabled) {
            return;
        }

        try {
            if ($action === 'delete' || $service->trashed()) {
                $itemId = trim((string) ($service->square_catalog_object_id ?? ''));
                if ($itemId !== '') {
                    try {
                        $this->squareApiClient->deleteCatalogObject($professional, $itemId);
                    } catch (SquareApiException $e) {
                        if ($e->status !== 404) {
                            throw $e;
                        }
                    }
                }

                Service::withoutEvents(function () use ($service): void {
                    Service::query()
                        ->withTrashed()
                        ->where('id', $service->id)
                        ->update([
                            'square_last_synced_at' => now(),
                            'square_sync_error' => null,
                        ]);
                });

                return;
            }

            $itemId = $this->existingOrTempId($service->square_catalog_object_id, 'item', $service->id);
            $variationId = $this->existingOrTempId($service->square_variation_id, 'var', $service->id);
            $itemVersion = $service->square_catalog_version;

            $catalogObject = [
                'type' => 'ITEM',
                'id' => $itemId,
                'item_data' => [
                    'name' => $service->title,
                    'description' => $service->description ?: null,
                    'product_type' => 'APPOINTMENTS_SERVICE',
                    'variations' => [[
                        'type' => 'ITEM_VARIATION',
                        'id' => $variationId,
                        'item_variation_data' => [
                            'item_id' => $itemId,
                            'name' => 'Default',
                            'pricing_type' => 'FIXED_PRICING',
                            'price_money' => [
                                'amount' => max(0, (int) ($service->price_cents ?? 0)),
                                'currency' => strtoupper((string) ($service->currency_code ?: 'AUD')),
                            ],
                            'service_duration' => $service->duration_minutes
                                ? ((int) $service->duration_minutes * 60 * 1000)
                                : null,
                            'available_for_booking' => (bool) $service->is_active,
                        ],
                    ]],
                ],
            ];

            if ($itemVersion !== null && ! str_starts_with($itemId, '#')) {
                $catalogObject['version'] = (int) $itemVersion;
            }

            try {
                $upserted = $this->squareApiClient->upsertCatalogObject($professional, $catalogObject);
            } catch (SquareApiException $e) {
                // If Square has a newer version than we have, retry once with latest version.
                if ($e->status !== 400 && $e->status !== 409) {
                    throw $e;
                }
                if (str_starts_with($itemId, '#')) {
                    throw $e;
                }

                $latest = $this->squareApiClient->retrieveCatalogObject($professional, $itemId);
                if (! $latest) {
                    throw $e;
                }

                $catalogObject['version'] = isset($latest['version']) ? (int) $latest['version'] : $catalogObject['version'] ?? null;
                $latestVariationId = data_get($latest, 'item_data.variations.0.id');
                if (is_string($latestVariationId) && $latestVariationId !== '') {
                    $catalogObject['item_data']['variations'][0]['id'] = $latestVariationId;
                    $catalogObject['item_data']['variations'][0]['item_variation_data']['item_id'] = $itemId;
                }

                $upserted = $this->squareApiClient->upsertCatalogObject($professional, $catalogObject);
            }

            $resolvedItemId = (string) ($upserted['id'] ?? $service->square_catalog_object_id ?? '');
            $resolvedVariationId = (string) (data_get($upserted, 'item_data.variations.0.id') ?? $service->square_variation_id ?? '');
            $resolvedVersion = isset($upserted['version']) ? (int) $upserted['version'] : $service->square_catalog_version;

            Service::withoutEvents(function () use ($service, $resolvedItemId, $resolvedVariationId, $resolvedVersion): void {
                Service::query()
                    ->withTrashed()
                    ->where('id', $service->id)
                    ->update([
                        'square_catalog_object_id' => $resolvedItemId !== '' ? $resolvedItemId : null,
                        'square_variation_id' => $resolvedVariationId !== '' ? $resolvedVariationId : null,
                        'square_catalog_version' => $resolvedVersion,
                        'square_last_synced_at' => now(),
                        'square_sync_error' => null,
                    ]);
            });
        } catch (\Throwable $e) {
            Service::withoutEvents(function () use ($service, $e): void {
                Service::query()
                    ->withTrashed()
                    ->where('id', $service->id)
                    ->update([
                        'square_sync_error' => mb_substr($e->getMessage(), 0, 2000),
                    ]);
            });

            Log::warning('Square push from Partna failed', [
                'service_id' => $service->id,
                'professional_id' => $service->professional_id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $squareRows
     * @return array{synced:int, deleted:int}
     */
    private function applySquareSnapshot(Professional $professional, array $squareRows, bool $fullSync): array
    {
        $syncedVariationIds = [];
        $syncedCount = 0;
        $deletedCount = 0;

        DB::transaction(function () use (
            $professional,
            $squareRows,
            &$syncedVariationIds,
            &$syncedCount,
            &$deletedCount
        ): void {
            $existingCategories = ServiceCategory::query()
                ->withTrashed()
                ->where('professional_id', $professional->id)
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->get();

            $activeCategoryIdByKey = [];
            $trashedCategoryByKey = [];
            $nextCategorySort = ((int) ($existingCategories->max('sort_order') ?? -1)) + 1;
            foreach ($existingCategories as $category) {
                $key = $this->categoryKey((string) $category->title);
                if ($key === '') {
                    continue;
                }

                if ($category->trashed()) {
                    $trashedCategoryByKey[$key] = $category;

                    continue;
                }

                $activeCategoryIdByKey[$key] = $category->id;
            }

            $nextGlobalSortOrder = (int) (Service::query()
                ->where('professional_id', $professional->id)
                ->whereNull('deleted_at')
                ->max('sort_order') ?? -1) + 1;

            Service::withoutEvents(function () use (
                $professional,
                $squareRows,
                &$syncedVariationIds,
                &$nextGlobalSortOrder,
                &$activeCategoryIdByKey,
                &$trashedCategoryByKey,
                &$nextCategorySort,
                &$syncedCount,
                &$deletedCount
            ): void {
                foreach ($squareRows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $itemId = trim((string) ($row['item_id'] ?? ''));
                    if ($itemId === '') {
                        continue;
                    }

                    $isDeleted = (bool) ($row['deleted'] ?? false);
                    if ($isDeleted) {
                        $variationId = trim((string) ($row['variation_id'] ?? ''));
                        $toDelete = Service::query()->where('professional_id', $professional->id);
                        if ($variationId !== '') {
                            $toDelete->where('square_variation_id', $variationId);
                        } else {
                            $toDelete->where('square_catalog_object_id', $itemId);
                        }
                        $toDelete = $toDelete
                            ->whereNull('deleted_at')
                            ->get();

                        foreach ($toDelete as $service) {
                            $service->is_active = false;
                            $service->deleted_origin = 'square';
                            $service->save();
                            $service->delete();
                            $deletedCount++;
                        }

                        continue;
                    }

                    $variationId = trim((string) ($row['variation_id'] ?? ''));
                    if ($variationId === '') {
                        continue;
                    }

                    $syncedVariationIds[] = $variationId;

                    $title = trim((string) ($row['item_name'] ?? 'Service'));
                    $variationName = trim((string) ($row['variation_name'] ?? ''));
                    $isGenericVariationName = in_array(mb_strtolower($variationName), ['regular', 'default'], true);
                    if ($variationName !== '' && ! $isGenericVariationName && strcasecmp($variationName, $title) !== 0) {
                        $title = sprintf('%s - %s', $title, $variationName);
                    }
                    $categoryId = $this->resolveCategoryIdFromSquareRow(
                        $professional->id,
                        $row,
                        $activeCategoryIdByKey,
                        $trashedCategoryByKey,
                        $nextCategorySort
                    );

                    $service = Service::query()
                        ->withTrashed()
                        ->where('professional_id', $professional->id)
                        ->where('square_variation_id', $variationId)
                        ->first();

                    $priceCents = isset($row['price_cents']) ? (int) $row['price_cents'] : null;
                    $currencyCode = strtoupper((string) ($row['currency_code'] ?? ''));
                    $durationMinutes = isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : null;
                    $availableForBooking = (bool) ($row['available_for_booking'] ?? false);
                    $itemVersion = isset($row['item_version']) ? (int) $row['item_version'] : null;

                    if (! $service) {
                        $service = Service::query()->create([
                            'professional_id' => $professional->id,
                            'category_id' => $categoryId,
                            'title' => $title,
                            'description' => isset($row['item_description']) ? (string) $row['item_description'] : null,
                            'price_cents' => $priceCents ?? 0,
                            'currency_code' => $currencyCode !== '' ? $currencyCode : 'AUD',
                            'duration_minutes' => $durationMinutes,
                            'is_active' => $availableForBooking,
                            'sort_order' => $nextGlobalSortOrder++,
                            'square_catalog_object_id' => $itemId,
                            'square_variation_id' => $variationId,
                            'square_catalog_version' => $itemVersion,
                            'square_last_synced_at' => now(),
                            'square_sync_error' => null,
                        ]);
                    } else {
                        // Don't resurrect a service the professional manually deleted.
                        // Only restore if Partna itself (via Square sync) did the deletion.
                        if ($service->trashed() && $service->deleted_origin !== 'square') {
                            continue;
                        }

                        $previousCategoryId = $service->category_id;
                        $wasTrashed = $service->trashed();
                        $service->fill([
                            'category_id' => $categoryId,
                            'title' => $title,
                            'description' => isset($row['item_description']) ? (string) $row['item_description'] : null,
                            'price_cents' => $priceCents ?? $service->price_cents ?? 0,
                            'currency_code' => $currencyCode !== '' ? $currencyCode : ($service->currency_code ?: 'AUD'),
                            'duration_minutes' => $durationMinutes,
                            'is_active' => $availableForBooking,
                            'square_catalog_object_id' => $itemId,
                            'square_variation_id' => $variationId,
                            'square_catalog_version' => $itemVersion ?? $service->square_catalog_version,
                            'square_last_synced_at' => now(),
                            'square_sync_error' => null,
                        ]);
                        if ($previousCategoryId !== $categoryId || $wasTrashed) {
                            $service->sort_order = $nextGlobalSortOrder++;
                        }
                        $service->save();
                    }

                    if ($service->trashed()) {
                        $service->restore();
                    }

                    $syncedCount++;
                }
            });
        });

        if ($fullSync) {
            Service::withoutEvents(function () use ($professional, $syncedVariationIds, &$deletedCount): void {
                $missingQuery = Service::query()
                    ->where('professional_id', $professional->id)
                    ->whereNotNull('square_variation_id')
                    ->whereNull('deleted_at');

                if (! empty($syncedVariationIds)) {
                    $missingQuery->whereNotIn('square_variation_id', array_values(array_unique($syncedVariationIds)));
                }

                $missing = $missingQuery->get();

                foreach ($missing as $service) {
                    $service->is_active = false;
                    $service->deleted_origin = 'square';
                    $service->save();
                    $service->delete();
                    $deletedCount++;
                }
            });
        }

        return [
            'synced' => $syncedCount,
            'deleted' => $deletedCount,
        ];
    }

    private function resolveCategoryIdFromSquareRow(
        string $professionalId,
        array $row,
        array &$activeCategoryIdByKey,
        array &$trashedCategoryByKey,
        int &$nextCategorySort
    ): ?string {
        $categoryName = trim((string) ($row['square_category_name'] ?? ''));
        if ($categoryName === '') {
            return null;
        }

        $key = $this->categoryKey($categoryName);
        if ($key === '') {
            return null;
        }

        if (isset($activeCategoryIdByKey[$key])) {
            return $activeCategoryIdByKey[$key];
        }

        if (isset($trashedCategoryByKey[$key])) {
            /** @var ServiceCategory $category */
            $category = $trashedCategoryByKey[$key];
            $category->restore();
            if ($category->title !== $categoryName) {
                $category->title = $categoryName;
            }
            if ($category->sort_order === null) {
                $category->sort_order = $nextCategorySort++;
            }
            $category->save();

            $activeCategoryIdByKey[$key] = $category->id;
            unset($trashedCategoryByKey[$key]);

            return $category->id;
        }

        $created = ServiceCategory::query()->create([
            'professional_id' => $professionalId,
            'title' => $categoryName,
            'sort_order' => $nextCategorySort++,
        ]);

        $activeCategoryIdByKey[$key] = $created->id;

        return $created->id;
    }

    private function categoryKey(string $title): string
    {
        return mb_strtolower(trim($title));
    }

    private function existingOrTempId(?string $existingId, string $prefix, string $serviceId): string
    {
        $trimmed = trim((string) $existingId);
        if ($trimmed !== '') {
            return $trimmed;
        }

        $hash = substr(str_replace('-', '', $serviceId), 0, 18);

        return sprintf('#partna-%s-%s', $prefix, $hash);
    }
}
