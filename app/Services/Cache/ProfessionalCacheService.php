<?php

namespace App\Services\Cache;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProfessionalCacheService
{
    /* ---------------------------
     |  ID mapping (fast lookups)
     * --------------------------*/

    public function getIdByAuthId(string $authUserId): ?string
    {
        /* return Cache::remember(
            CacheKeyGenerator::professionalIdByAuthId($authUserId),
            now()->addMinutes(30),
            fn () => Professional::query()
                ->where('auth_user_id', $authUserId)
                ->value('id')
        ); */   

        Log::info('ProfessionalCacheService getIdByAuthId start', ['auth_user_id' => $authUserId]);

        // TEMP: no cache, direct DB query
        $id = Professional::query()
            ->where('auth_user_id', $authUserId)
            ->value('id');

        Log::info('ProfessionalCacheService getIdByAuthId end', [
            'auth_user_id' => $authUserId,
        '   id'           => $id,
        ]);

        return $id;
    }

    public function getIdByHandle(string $handle): ?string
    {
        $handleLc = strtolower($handle);

        return Cache::remember(
            CacheKeyGenerator::professionalIdByHandle($handleLc),
            now()->addHour(),
            fn () => Professional::query()
                ->where('handle_lc', $handleLc)
                ->value('id')
        );
    }

    /* ---------------------------
     |  Payload (array pattern)
     * --------------------------*/

    public function getPayloadById(string $id): ?array
    {
        return Cache::remember(
            CacheKeyGenerator::professionalPayloadById($id),
            now()->addHour(),
            function () use ($id) {
                $pro = Professional::query()->with('site')->find($id);
                return $pro ? $this->toPayload($pro) : null;
            }
        );
    }

    public function getPayloadByHandle(string $handle): ?array
    {
        $handleLc = strtolower($handle);
        $id = $this->getIdByHandle($handleLc);

        return $id ? $this->getPayloadById($id) : null;
    }

    public function getPayloadByAuthId(string $authUserId): ?array
    {
        $id = $this->getIdByAuthId($authUserId);
        return $id ? $this->getPayloadById($id) : null;
    }

    private function toPayload(Professional $pro): array
    {
        // NOTE: your Professional model has protected $with = ['site'];
        $site = $pro->site;

        return [
            'professional' => [
                'id' => $pro->id,
                'auth_user_id' => $pro->auth_user_id,
                'handle' => $pro->handle,
                'handle_lc' => $pro->handle_lc,
                'display_name' => $pro->display_name,
                'bio' => $pro->bio,
                'country_code' => $pro->country_code,
                'timezone' => $pro->timezone,
                'status' => $pro->status,
                'onboarding_step' => $pro->onboarding_step,
                'qr_slug' => $pro->qr_slug,

                'public_contact_number' => $pro->public_contact_number,
                'public_contact_email' => $pro->public_contact_email,

                'icon_bucket' => $pro->icon_bucket,
                'icon_path' => $pro->icon_path,
                'headshot_bucket' => $pro->headshot_bucket,
                'headshot_path' => $pro->headshot_path,

                'location_street_address' => $pro->location_street_address,
                'location_city' => $pro->location_city,
                'location_state' => $pro->location_state,
                'location_postcode' => $pro->location_postcode,
                'location_country' => $pro->location_country,

                'created_at' => optional($pro->created_at)->toIso8601String(),
                'updated_at' => optional($pro->updated_at)->toIso8601String(),
            ],
            'site' => $site ? [
                'id' => $site->id,
                'subdomain' => $site->subdomain,
                'is_published' => (bool) $site->is_published,
                'settings' => $site->settings,
            ] : null,
        ];
    }

    /* ---------------------------
     |  Keep model-returning helpers (no model caching)
     * --------------------------*/

    public function getByAuthId(string $authUserId): ?Professional
    {
        /* $id = $this->getIdByAuthId($authUserId);
        return $id ? Professional::query()->find($id) : null; */

        Log::info('ProfessionalCacheService getByAuthId start', ['auth_user_id' => $authUserId]);

        $id = $this->getIdByAuthId($authUserId);

        Log::info('ProfessionalCacheService getByAuthId after getIdByAuthId', [
            'auth_user_id' => $authUserId,
            'id'           => $id,
        ]);

        if (!$id) {
            Log::info('ProfessionalCacheService getByAuthId no id found', ['auth_user_id' => $authUserId]);
            return null;
        }

        $pro = Professional::query()->find($id);

        Log::info('ProfessionalCacheService getByAuthId after find', [
            'auth_user_id' => $authUserId,
            'pro_id'       => optional($pro)->id,
        ]);

        return $pro;
    }

    /* ---------------------------
     |  Existing caches you already have
     * --------------------------*/

    public function getActiveServices(string $professionalId): array
    {
        return Cache::remember(
            CacheKeyGenerator::professionalServices($professionalId),
            now()->addMinutes(30),
            fn () => Service::query()
                ->where('professional_id', $professionalId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->get()
                ->toArray()
        );
    }

    public function getCustomerCount(string $professionalId): int
    {
        return Cache::remember(
            CacheKeyGenerator::customerCount($professionalId),
            now()->addMinutes(15),
            fn () => DB::table('core.customers')
                ->where('professional_id', $professionalId)
                ->whereNull('deleted_at')
                ->count()
        );
    }

    public function invalidateProfessional(Professional $professional): void
    {
        $handleLc = strtolower($professional->handle);

        $keys = [
            CacheKeyGenerator::professionalPayloadById($professional->id),
            CacheKeyGenerator::professionalPayloadByHandle($handleLc),
            CacheKeyGenerator::professionalPayloadByAuthId($professional->auth_user_id),

            CacheKeyGenerator::professionalIdByHandle($handleLc),
            CacheKeyGenerator::professionalIdByAuthId($professional->auth_user_id),

            CacheKeyGenerator::professionalServices($professional->id),
            CacheKeyGenerator::customerCount($professional->id),
        ];

        if ($professional->wasChanged('handle')) {
            $old = strtolower((string) $professional->getOriginal('handle'));
            if ($old !== '') {
                $keys[] = CacheKeyGenerator::professionalPayloadByHandle($old);
                $keys[] = CacheKeyGenerator::professionalIdByHandle($old);
            }
        }

        if ($professional->wasChanged('auth_user_id')) {
            $old = (string) $professional->getOriginal('auth_user_id');
            if ($old !== '') {
                $keys[] = CacheKeyGenerator::professionalPayloadByAuthId($old);
                $keys[] = CacheKeyGenerator::professionalIdByAuthId($old);
            }
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));

        // Also invalidate site cache (public payload includes professional fields)
        if ($professional->site) {
            app(SiteCacheService::class)->invalidateSite($professional->site);
        }
    }
}
