<?php

namespace App\Services\Media;

/**
 * Thrown when an image cannot be processed due to a permanent, non-retryable
 * condition (e.g. pixel dimensions exceed the safe decode ceiling). The
 * ProcessImageVariantsJob recognises this class and skips the retry path,
 * marking the SiteMedia row as failed on the first attempt.
 */
class UnprocessableImageException extends \RuntimeException {}
