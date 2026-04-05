<?php

namespace App\Services\Fresha;

use RuntimeException;

// V2: Typed exception for Fresha API failures, carrying HTTP status and response payload.
class FreshaApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?array $payload = null
    ) {
        parent::__construct($message, $status);
    }
}
