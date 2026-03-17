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
