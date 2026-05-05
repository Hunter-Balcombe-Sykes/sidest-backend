<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\UploadedFile;

// Adds magic-byte MIME verification to upload Form Requests. Laravel's `mimes:` rule
// trusts the client-supplied Content-Type header; this trait reads actual file bytes
// via finfo so a disguised file (e.g. PHP renamed to .jpg) is caught at the HTTP layer.
trait SniffsFileMimeType
{
    /** @var string[] SVG and other non-raster types are excluded. */
    private const IMAGE_MIME_WHITELIST = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Verify that the uploaded file's actual bytes match an allowed image MIME type.
     * Call from withValidator() after standard rules have run.
     * Skips the check when the field already carries a validation error so the user
     * sees one clear message rather than two contradictory ones.
     */
    protected function assertImageMimeBytes(UploadedFile $file, Validator $v, string $field): void
    {
        if ($v->errors()->has($field)) {
            return;
        }

        $actual = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname()) ?: '';

        if (! in_array($actual, self::IMAGE_MIME_WHITELIST, true)) {
            $v->errors()->add($field, 'The uploaded file is not a valid image. Only JPEG, PNG, and WebP are accepted.');
        }
    }
}
