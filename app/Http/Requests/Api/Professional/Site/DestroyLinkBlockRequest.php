<?php

namespace App\Http\Requests\Api\Professional\Site;

use Illuminate\Foundation\Http\FormRequest;

class DestroyLinkBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => $this->route('block'),
        ]);
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
        ];
    }
}
