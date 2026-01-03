<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Actions\Site\UpdateSiteAction;
use App\Http\Controllers\Controller;
use App\Models\Core\Site\Theme;
use Illuminate\Http\Request;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;

class ProfessionalThemeController extends Controller
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    public function index()
    {
        $themes = Theme::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'key', 'name', 'description', 'config', 'is_default']);

        return response()->json(['themes' => $themes]);
    }

    public function select(Request $request, Theme $theme, UpdateSiteAction $action)
    {
        $professional = $this->currentProfessional($request);

        $site = $action->execute($professional, [
            'theme_id' => $theme->id,
        ]);

        return response()->json(['site' => $site]);
    }
}
