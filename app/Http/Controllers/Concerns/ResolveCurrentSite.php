<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\Controller;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Validation\ValidationException;

trait ResolveCurrentSite
{
    protected function currentSite(Professional $professional): Site
    {
        $site = $professional->site;

        if (!$site) {
            throw ValidationException::withMessages([
                'site' => 'Professional has no site.',
            ]);
        }

        return $site;
    }
}
