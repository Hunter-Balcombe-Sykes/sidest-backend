<?php

namespace App\Observers\Concerns;

trait LogsWithRequestContext
{
    /**
     * Build the standard log context array.
     * Merges request_id and operation with any callsite-specific fields.
     *
     * @param  string  $operation  Typically __METHOD__ from the call site.
     * @param  array<string, mixed>  $extra  Callsite-specific fields (tenant ID, model ID, etc.).
     * @return array<string, mixed>
     */
    protected function logContext(string $operation, array $extra = []): array
    {
        return array_merge([
            'request_id' => request()->header('X-Request-Id', ''),
            'operation' => $operation,
        ], $extra);
    }
}
