<?php

namespace App\Http\Requests\Api\Professional\Site;

use Illuminate\Foundation\Http\FormRequest;

class ReorderBlocksRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'ids' => ['required','array','min:1'],
            'ids.*' => ['uuid'],
        ];
    }
}
