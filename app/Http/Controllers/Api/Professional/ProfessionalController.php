<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Professional\ProfessionalShowRequest;
use App\Http\Requests\Api\Professional\UpdateProfessionalRequest;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Cache\SiteCacheService;

class ProfessionalController extends ApiController
{

    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    public function show(ProfessionalShowRequest $request)
    {
        $uid = $request->attributes->get('supabase_uid');
        $pro = $this->currentProfessional($request);

        $cache = app(ProfessionalCacheService::class);

        $payload = $cache->getPayloadById($pro->id);
        $services = $cache->getActiveServices($pro->id);
        $customersCount = $cache->getCustomerCount($pro->id);
        $blocks = $pro->site
            ? app(SiteCacheService::class)->getSiteLinkBlocks($pro->site->id)
            : [];


        return $this->success([
            'uid' => $uid,
            ...($payload ?? ['professional' => $pro->toArray(), 'site' => $pro->site?->toArray()]),
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
