<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Professional\ProfessionalShowRequest;
use App\Http\Requests\Api\Professional\UpdateProfessionalRequest;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Block;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Returns authenticated professional's full profile with site, services, and blocks. Dashboard entry point.
class ProfessionalController extends ApiController
{
    /** @return array<int, string> */
    private function professionalOnlySectionTypes(): array
    {
        return config('sidest.professional_only_section_types', []);
    }

    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    public function show(ProfessionalShowRequest $request)
    {
        $uid = $request->attributes->get('supabase_uid');
        Log::info('/api/me start');

        $pro = $this->currentProfessional($request);
        $squareIntegration = $pro->integrationForProvider(ProfessionalIntegration::PROVIDER_SQUARE);
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

        $siteSettings = [];
        if ($pro->site) {
            $siteSettings = is_array($pro->site->settings) ? $pro->site->settings : [];
            $siteSettings = app(SiteCacheService::class)->hydrateTypographySettings(
                $siteSettings,
                (string) $pro->id
            );
        }

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
                'phone' => $pro->phone,
                'primary_email' => $pro->primary_email,
                'bio' => $pro->bio,
                'country_code' => $pro->country_code,
                'timezone' => $pro->timezone,
                'professional_type' => $pro->professional_type,
                'status' => $pro->status,
                'onboarding_step' => $pro->onboarding_step,
                'qr_slug' => $pro->qr_slug,
                'public_contact_number' => $pro->public_contact_number,
                'public_contact_email' => $pro->public_contact_email,
                'location_street_address' => $pro->location_street_address,
                'location_city' => $pro->location_city,
                'location_state' => $pro->location_state,
                'location_postcode' => $pro->location_postcode,
                'location_country' => $pro->location_country,
                'created_at' => optional($pro->created_at)->toIso8601String(),
                'updated_at' => optional($pro->updated_at)->toIso8601String(),
                'square_connected' => $squareIntegration
                    && ! empty($squareIntegration->access_token)
                    && ! empty($squareIntegration->external_account_id),
                'square_merchant_id' => $squareIntegration?->external_account_id,
            ],
            'site' => $pro->site ? [
                'id' => $pro->site->id,
                'subdomain' => $pro->site->subdomain,
                'is_published' => (bool) $pro->site->is_published,
                'settings' => $siteSettings,
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
        $previousProfessionalType = mb_strtolower(trim((string) ($professional->professional_type ?? '')));

        DB::transaction(function () use ($professional, $request, $previousProfessionalType): void {
            $professional->fill($request->validated());
            $professional->save();

            $nextProfessionalType = mb_strtolower(trim((string) ($professional->professional_type ?? '')));
            if ($previousProfessionalType !== 'influencer' && $nextProfessionalType === 'influencer') {
                $this->disableProfessionalOnlySections($professional->id);
            }
        });

        return $this->success([
            'professional' => new \App\Http\Resources\ProfessionalResource($professional->fresh()),
        ]);
    }

    private function disableProfessionalOnlySections(string $professionalId): void
    {
        if ($professionalId === '') {
            return;
        }

        Block::query()
            ->where('professional_id', $professionalId)
            ->where('block_group', 'sections')
            ->whereIn('block_type', $this->professionalOnlySectionTypes())
            ->where('is_active', true)
            ->update([
                'is_active' => false,
            ]);
    }

}
