<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Actions\Site\UpdateSiteAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Professional\Site\UpdateSiteRequest;
use Illuminate\Http\Request;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
class ProfessionalSiteController extends Controller
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    public function show(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);
        return response()->json(['site' => $site]);
    }
    public function update(UpdateSiteRequest $request, UpdateSiteAction $action)
    {
        $professional = $this->currentProfessional($request);
        $data = $request->validated();
        $site = $action->execute($professional, $data);
        return response()->json(['site' => $site]);
    }
}
