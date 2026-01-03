<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Professional\ProfessionalShowRequest;
use App\Http\Requests\Api\Professional\UpdateProfessionalRequest;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
class ProfessionalController extends Controller
{

    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    public function show(ProfessionalShowRequest $request)
    {
        $uid = $request->attributes->get('supabase_uid');
        $professional = $this->currentProfessional($request); // set by current.pro

        // Load related rows for dashboard
        $professional->load(['site', 'linkBlocks',]);

        $customersCount = $professional->customers()->count();

        return response()->json([
            'uid' => $uid,
            'professional' => $professional,
            'site' => $professional->site,
            'blocks' => $professional->linkBlocks,
            'customers_count' => $customersCount,
        ]);
    }

    public function update(UpdateProfessionalRequest $request)
    {
        $professional = $this->currentProfessional($request);

        $professional->fill($request->validated());
        $professional->save();

        // return the updated pro (fresh)
        return response()->json([
            'professional' => $professional->fresh(),
        ]);
    }

}
