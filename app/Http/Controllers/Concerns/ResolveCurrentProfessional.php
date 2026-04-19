<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;

// V2: Extracts the authenticated Professional model from request attributes set by the current.pro middleware.
trait ResolveCurrentProfessional
{
    protected function currentProfessional(Request $request): Professional
    {
        $pro = $request->attributes->get('professional');

        if (! $pro instanceof Professional) {
            abort(401, 'Professional not loaded. Ensure current.pro middleware is applied.');
        }

        return $pro;
    }
}
