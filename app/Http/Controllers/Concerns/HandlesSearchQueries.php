<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait HandlesSearchQueries
{
    protected function prepareSearchLike(Request $request, string $param = 'search'): ?string
    {
        $search = $request->query($param);
        $search = is_string($search) ? trim($search) : null;

        if (!  $search) {
            return null;
        }

        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%';
    }
}
