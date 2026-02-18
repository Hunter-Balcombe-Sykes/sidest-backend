<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Professional\ProfessionalShowRequest;
use App\Http\Requests\Api\Professional\UpdateProfessionalRequest;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Log;

class ProfessionalController extends ApiController
{

    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    public function show(ProfessionalShowRequest $request)
    {
        $uid = $request->attributes->get('supabase_uid');
        Log::info('/api/me start');

        $pro = $this->currentProfessional($request);
        Log::info('/api/me after currentProfessional', ['pro_id' => $pro->id]);

        $cache = app(ProfessionalCacheService::class);

        $t = microtime(true);
        $payload = $cache->getPayloadById($pro->id);
        Log::info('/api/me after payload', ['ms' => (microtime(true) - $t) * 1000]);

        $t = microtime(true);
        $services = $cache->getActiveServices($pro->id);
        Log::info('/api/me after services', ['ms' => (microtime(true) - $t) * 1000]);

        $t = microtime(true);
        $customersCount = $cache->getCustomerCount($pro->id);
        Log::info('/api/me after customers', ['ms' => (microtime(true) - $t) * 1000]);

        // Use the already-loaded professional to build payload instead of querying again
        $payload = [
            'professional' => [
                'id' => $pro->id,
                'auth_user_id' => $pro->auth_user_id,
                'handle' => $pro->handle,
                'handle_lc' => $pro->handle_lc,
                'display_name' => $pro->display_name,
                'first_name' => $pro->first_name,
                'last_name' => $pro->last_name,
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
                'square_connected' => !empty($pro->square_access_token) && !empty($pro->square_merchant_id),
                'square_merchant_id' => $pro->square_merchant_id,
            ],
            'site' => $pro->site ? [
                'id' => $pro->site->id,
                'subdomain' => $pro->site->subdomain,
                'is_published' => (bool) $pro->site->is_published,
                'settings' => $pro->site->settings,
            ] : null,
        ];

        $services = $cache->getActiveServices($pro->id);
        $customersCount = $cache->getCustomerCount($pro->id);
        $blocks = $pro->site
            ? app(SiteCacheService::class)->getSiteLinkBlocks($pro->site->id)
            : [];

        return $this->success([
            'uid' => $uid,
            ...$payload,
            'blocks' => $blocks,
            'services' => $services,
            'customers_count' => $customersCount,
        ]);
    }

    public function update(UpdateProfessionalRequest $request)
    {
        $professional = $this->currentProfessional($request);

        $professional->fill($request->validated());
        $professional->save();

        // return the updated pro (fresh)
        return $this->success([
            'professional' => $professional->fresh(),
        ]);
    }

}
