<?php

namespace App\Services\Fresha;

use RuntimeException;

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
