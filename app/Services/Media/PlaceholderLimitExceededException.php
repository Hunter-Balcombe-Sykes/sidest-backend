<?php

namespace App\Services\Media;

class PlaceholderLimitExceededException extends \DomainException
{
    public function __construct(int $max)
    {
        parent::__construct("Placeholder image limit reached (max {$max}).");
    }
}
