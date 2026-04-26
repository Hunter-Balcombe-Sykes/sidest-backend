<?php

namespace App\Exceptions\Gdpr;

use RuntimeException;

class NoRecipientEmailException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No valid recipient email on file.');
    }
}
