<?php

namespace App\Services\Stripe;

use RuntimeException;
use Throwable;

// Raised by CommissionAdjustmentService when the caller-supplied {reference}
// has already been used (unique-constraint violation on idempotency_key).
// The controller maps this to a 409 response.
class DuplicateAdjustmentException extends RuntimeException
{
    public function __construct(
        public readonly string $reference,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Commission adjustment with reference '{$reference}' already exists.", 0, $previous);
    }
}
