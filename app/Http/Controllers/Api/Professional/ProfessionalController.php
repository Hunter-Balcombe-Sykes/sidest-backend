<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Professional\ProfessionalShowRequest;
use App\Http\Requests\Api\Professional\UpdateProfessionalRequest;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
class ProfessionalController extends ApiController
{

    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    public function show(ProfessionalShowRequest $request)
    {
        $uid = $request->attributes->get('supabase_uid');
        $pro = $this->currentProfessional($request); // set by current.pro

        // Load related rows for dashboard
        $pro->load(['site', 'services', 'linkBlocks']);

        $customersCount = $pro->customers()->count();

        return $this->success([
            'uid' => $uid,
            'professional' => $pro,
            'site' => $pro->site,
            'blocks' => $pro->linkBlocks,
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
