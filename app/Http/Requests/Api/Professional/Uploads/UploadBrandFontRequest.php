<?php

namespace App\Http\Requests\Api\Professional\Uploads;

use App\Http\Requests\BaseFormRequest;
use Closure;

class UploadBrandFontRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'font' => [
                'required',
                'file',
                'max:5120',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (!method_exists($value, 'getClientOriginalExtension')) {
                        $fail('The uploaded file is invalid.');
                        return;
                    }

                    $extension = strtolower((string) $value->getClientOriginalExtension());
                    if ($extension !== 'woff2') {
                        $fail('Font file must be a .woff2 file.');
                        return;
                    }

                    if (! method_exists($value, 'getMimeType')) {
                        $fail('Unable to verify font mime type.');
                        return;
                    }

                    $mime = strtolower((string) $value->getMimeType());
                    $allowedMimes = [
                        'font/woff2',
                        'application/font-woff2',
                        'application/x-font-woff2',
                        'application/octet-stream',
                    ];

                    if (! in_array($mime, $allowedMimes, true)) {
                        $fail('Font file mime type must be WOFF2.');
                        return;
                    }

                    if (! method_exists($value, 'getRealPath')) {
                        $fail('Unable to verify font file signature.');
                        return;
                    }

                    $realPath = $value->getRealPath();
                    if (! is_string($realPath) || $realPath === '') {
                        $fail('Unable to read uploaded font file.');
                        return;
                    }

                    $handle = @fopen($realPath, 'rb');
                    if (! is_resource($handle)) {
                        $fail('Unable to inspect uploaded font file.');
                        return;
                    }

                    $signature = fread($handle, 4);
                    fclose($handle);

                    if ($signature !== 'wOF2') {
                        $fail('Font file signature is invalid. Upload a valid WOFF2 font.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'font.max' => 'Font file must be smaller than 5 MB.',
        ];
    }
}
