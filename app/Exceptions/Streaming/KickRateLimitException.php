<?php

namespace App\Exceptions\Streaming;

use RuntimeException;

/** Thrown by KickApiClient when Kick returns HTTP 429. */
class KickRateLimitException extends RuntimeException
{
    public function __construct(
        public readonly ?int $retryAfter = null
    ) {
        parent::__construct('Kick API rate limit exceeded.');
    }
}
