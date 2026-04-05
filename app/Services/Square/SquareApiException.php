<?php

namespace App\Services\Square;

use RuntimeException;

// V2: Typed exception for Square API failures, carrying HTTP status and response payload.
class SquareApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?array $payload = null
    ) {
        parent::__construct($message, $status);
    }
}

