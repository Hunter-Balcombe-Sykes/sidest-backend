<?php

namespace App\Services\Square;

use RuntimeException;

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

