<?php

namespace App\Exceptions\Gdpr;

use RuntimeException;

class DataExportInProgressException extends RuntimeException
{
    public function __construct(public string $existingExportId)
    {
        parent::__construct('A data export is already in progress for this professional.');
    }
}
