<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

abstract class ApiController extends Controller
{
    /**
     * Return success response with data.
     */
    protected function success($data = null, int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }

    /**
     * Return error response with message.
     */
    protected function error(string $message, int $status = 400, array $errors = []): JsonResponse
    {
        $response = ['message' => $message];

        if (! empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $status);
    }

    /**
     * Return paginated response.
     */
    protected function paginated($paginator, string $dataKey = 'data'): JsonResponse
    {
        return response()->json([
            $dataKey => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
            ],
        ]);
    }
}
