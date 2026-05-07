<?php

namespace App\Services\Fresha;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Professional\Service;
use App\Models\Core\Professional\ServiceCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// V2: Bidirectional service sync between Fresha and Partna. Booking integration — not V2 commerce.
class FreshaServiceSyncService
{
    public function __construct(
        private readonly FreshaApiClient $freshaApiClient
    ) {}

    /**
     * Pull services from Fresha and upsert into Partna.
     * Mirrors SquareServiceSyncService::syncFromSquare().
     *
     * @return array{synced:int, deleted:int, latest_time:string|null}
     */
    public function syncFromFresha(Professional $professional, bool $fullSync = false, ?string $beginTimeOverride = null): array
    {
        $integration = $professional->integrationForProvider(ProfessionalIntegration::PROVIDER_FRESHA);
        if (! $integration || empty($integration->access_token) || empty($integration->external_account_id)) {
            return ['synced' => 0, 'deleted' => 0, 'latest_time' => null];
        }

        $beginTime = $beginTimeOverride;
        if ($beginTime === null && ! $fullSync && $integration->catalog_latest_time) {
            $beginTime = $integration->catalog_latest_time->toIso8601String();
        }

        try {
            $result = $this->freshaApiClient->fetchServices($professional, $fullSync ? null : $beginTime);
            $rows = $result['services'] ?? [];
            $latestTime = $result['latest_time'] ?? null;

            $syncedCount = 0;
            $deletedCount = 0;

            DB::transaction(function () use ($professional, $rows, &$syncedCount, &$deletedCount) {
                foreach ($rows as $row) {
                    $serviceId = trim((string) ($row['service_id'] ?? ''));
                    if ($serviceId === '') {
                        continue;
                    }

                    $isDeleted = (bool) ($row['deleted'] ?? false);
                    if ($isDeleted) {
                        $variationId = trim((string) ($row['variation_id'] ?? ''));
                        $toDelete = Service::query()->where('professional_id', $professional->id);
                        if ($variationId !== '') {
                            $toDelete->where('fresha_variation_id', $variationId);
                        } else {
                            $toDelete->where('fresha_service_id', $serviceId);
                        }
                        $toDelete = $toDelete
                            ->whereNull('deleted_at')
                            ->get();

                        foreach ($toDelete as $service) {
                            $service->is_active = false;
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

                    $title = trim((string) ($row['item_name'] ?? ''));
                    if ($title === '') {
                        continue;
                    }

                    // Find or create category
                    $categoryId = null;
                    $categoryName = trim((string) ($row['category_name'] ?? ''));
                    if ($categoryName !== '') {
                        $category = ServiceCategory::query()
                            ->where('professional_id', $professional->id)
                            ->where('title', $categoryName)
                            ->first();

                        if (! $category) {
                            $maxSort = ServiceCategory::query()
                                ->where('professional_id', $professional->id)
                                ->max('sort_order') ?? -1;

                            $category = ServiceCategory::query()->create([
                                'professional_id' => $professional->id,
                                'title' => $categoryName,
                                'sort_order' => $maxSort + 1,
                            ]);
                        }
                        $categoryId = $category->id;
                    }

                    // Find existing service by fresha_variation_id
                    $service = Service::query()
                        ->withTrashed()
                        ->where('professional_id', $professional->id)
                        ->where('fresha_variation_id', $variationId)
                        ->first();

                    $priceCents = isset($row['price_cents']) ? (int) $row['price_cents'] : null;
                    $currencyCode = strtoupper((string) ($row['currency_code'] ?? ''));
                    $durationMinutes = isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : null;
                    $availableForBooking = (bool) ($row['available_for_booking'] ?? false);
                    $itemVersion = isset($row['item_version']) ? (int) $row['item_version'] : null;

                    if (! $service) {
                        $maxSort = Service::query()
                            ->where('professional_id', $professional->id)
                            ->max('sort_order') ?? -1;

                        $service = Service::query()->create([
                            'professional_id' => $professional->id,
                            'category_id' => $categoryId,
                            'title' => $title,
                            'description' => isset($row['item_description']) ? (string) $row['item_description'] : null,
                            'price_cents' => $priceCents ?? 0,
                            'currency_code' => $currencyCode !== '' ? $currencyCode : 'AUD',
                            'duration_minutes' => $durationMinutes,
                            'is_active' => $availableForBooking,
                            'sort_order' => $maxSort + 1,
                            'fresha_service_id' => $serviceId,
                            'fresha_variation_id' => $variationId,
                            'fresha_service_version' => $itemVersion,
                            'fresha_last_synced_at' => now(),
                            'fresha_sync_error' => null,
                        ]);
                    } else {
                        // Restore if soft-deleted
                        if ($service->trashed()) {
                            $service->restore();
                        }

                        Service::withoutEvents(function () use ($service, $title, $categoryId, $row, $priceCents, $currencyCode, $durationMinutes, $availableForBooking, $serviceId, $variationId, $itemVersion): void {
                            Service::query()
                                ->withTrashed()
                                ->where('id', $service->id)
                                ->update([
                                    'title' => $title,
                                    'category_id' => $categoryId,
                                    'description' => isset($row['item_description']) ? (string) $row['item_description'] : $service->description,
                                    'price_cents' => $priceCents ?? $service->price_cents,
                                    'currency_code' => $currencyCode !== '' ? $currencyCode : $service->currency_code,
                                    'duration_minutes' => $durationMinutes ?? $service->duration_minutes,
                                    'is_active' => $availableForBooking,
                                    'fresha_service_id' => $serviceId,
                                    'fresha_variation_id' => $variationId,
                                    'fresha_service_version' => $itemVersion,
                                    'fresha_last_synced_at' => now(),
                                    'fresha_sync_error' => null,
                                ]);
                        });
                    }

                    $syncedCount++;
                }
            });

            $integration->catalog_latest_time = $latestTime ? CarbonImmutable::parse($latestTime) : now();
            $integration->last_catalog_sync_at = now();
            $integration->last_catalog_sync_error = null;
            $integration->save();

            return ['synced' => $syncedCount, 'deleted' => $deletedCount, 'latest_time' => $latestTime];
        } catch (\Throwable $e) {
            $integration->last_catalog_sync_error = mb_substr($e->getMessage(), 0, 2000);
            $integration->last_catalog_sync_at = now();
            $integration->save();
            throw $e;
        }
    }

    /**
     * Push one Partna service mutation to Fresha.
     * Mirrors SquareServiceSyncService::pushServiceToSquare().
     *
     * NOTE: May require partner-level API access. If Fresha's API is read-only for services,
     *       this will throw a FreshaApiException which is logged and swallowed by the observer/job.
     */
    public function pushServiceToFresha(Service $service, string $action = 'upsert'): void
    {
        $professional = Professional::query()->find($service->professional_id);
        if (! $professional) {
            return;
        }
        $integration = $professional->integrationForProvider(ProfessionalIntegration::PROVIDER_FRESHA);
        if (! $integration || empty($integration->access_token) || empty($integration->external_account_id)) {
            return;
        }

        // Respect "smart sync" toggle when present (same site settings key as Square).
        $smartSyncEnabled = (bool) data_get($professional->site?->settings, 'services_auto_sync_enabled', false);
        if (! $smartSyncEnabled) {
            return;
        }

        try {
            if ($action === 'delete' || $service->trashed()) {
                $serviceId = trim((string) ($service->fresha_service_id ?? ''));
                if ($serviceId !== '') {
                    try {
                        $this->freshaApiClient->deleteService($professional, $serviceId);
                    } catch (FreshaApiException $e) {
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
                            'fresha_last_synced_at' => now(),
                            'fresha_sync_error' => null,
                        ]);
                });

                return;
            }

            // Build the Fresha service payload.
            // NOTE: Update field names based on actual Fresha API request format.
            $serviceData = [
                'name' => $service->title,
                'description' => $service->description ?? '',
                'duration' => $service->duration_minutes,
                'price' => $service->price_cents,
                'currency' => $service->currency_code ?? 'AUD',
                'active' => (bool) $service->is_active,
            ];

            // Include category name if available
            if ($service->category_id) {
                $category = ServiceCategory::query()->find($service->category_id);
                if ($category) {
                    $serviceData['category'] = ['name' => $category->title];
                }
            }

            $serviceId = trim((string) ($service->fresha_service_id ?? ''));

            if ($serviceId !== '') {
                // Update existing
                try {
                    $upserted = $this->freshaApiClient->updateService($professional, $serviceId, $serviceData);
                } catch (FreshaApiException $e) {
                    // If version conflict, retry with latest version
                    if ($e->status !== 400 && $e->status !== 409) {
                        throw $e;
                    }

                    $latest = $this->freshaApiClient->retrieveService($professional, $serviceId);
                    if (! $latest) {
                        throw $e;
                    }

                    if (isset($latest['version'])) {
                        $serviceData['version'] = (int) $latest['version'];
                    }
                    $upserted = $this->freshaApiClient->updateService($professional, $serviceId, $serviceData);
                }
            } else {
                // Create new
                $upserted = $this->freshaApiClient->createService($professional, $serviceData);
            }

            // Extract returned IDs and version from Fresha response
            $returnedServiceId = (string) ($upserted['id'] ?? $upserted['data']['id'] ?? $serviceId);
            $returnedVariationId = (string) ($upserted['variation_id'] ?? $upserted['data']['variation_id'] ?? $returnedServiceId);
            $returnedVersion = isset($upserted['version']) ? (int) $upserted['version'] : (isset($upserted['data']['version']) ? (int) $upserted['data']['version'] : null);

            Service::withoutEvents(function () use ($service, $returnedServiceId, $returnedVariationId, $returnedVersion): void {
                Service::query()
                    ->withTrashed()
                    ->where('id', $service->id)
                    ->update([
                        'fresha_service_id' => $returnedServiceId,
                        'fresha_variation_id' => $returnedVariationId,
                        'fresha_service_version' => $returnedVersion,
                        'fresha_last_synced_at' => now(),
                        'fresha_sync_error' => null,
                    ]);
            });

        } catch (FreshaApiException $e) {
            Service::withoutEvents(function () use ($service, $e): void {
                Service::query()
                    ->withTrashed()
                    ->where('id', $service->id)
                    ->update([
                        'fresha_last_synced_at' => now(),
                        'fresha_sync_error' => mb_substr($e->getMessage(), 0, 2000),
                    ]);
            });
            throw $e;
        }
    }
}
