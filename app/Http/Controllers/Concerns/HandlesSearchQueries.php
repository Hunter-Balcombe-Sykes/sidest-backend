<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

// V2: Sanitizes and escapes user search input into a safe SQL LIKE pattern for wildcard queries.
trait HandlesSearchQueries
{
    protected function prepareSearchLike(Request $request, string $param = 'search'): ?string
    {
        $search = $request->query($param);
        $search = is_string($search) ? trim($search) : null;

        if (! $search) {
            return null;
        }

        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
    }
}
