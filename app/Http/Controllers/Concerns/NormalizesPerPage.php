<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait NormalizesPerPage
{
    protected function normalizePerPage(Request $request, int $default = 25, int $max = 100): int
    {
        $perPage = (int) $request->query('per_page', $default);
        return max(1, min($max, $perPage));
    }
}
