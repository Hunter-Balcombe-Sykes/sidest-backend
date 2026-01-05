<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

trait ReturnsPaginatedResponse
{
    protected function paginatedResponse(
        LengthAwarePaginator $paginator,
        string $dataKey = 'data',
        array $additional = []
    ): array {
        return array_merge([
            $dataKey => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
            ],
        ], $additional);
    }
}
