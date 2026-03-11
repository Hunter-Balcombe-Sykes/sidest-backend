<?php

namespace App\Http\Requests\Api\Professional\Uploads;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class ReorderPoolImagesRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'pool' => [
                'required',
                'string',
                Rule::in(['gallery', 'content']),
            ],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->pool ?? null)) {
            $this->merge(['pool' => strtolower(trim($this->pool))]);
        }
    }
}
