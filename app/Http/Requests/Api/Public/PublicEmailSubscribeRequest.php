<?php

namespace App\Http\Requests\Api\Public;

use Illuminate\Foundation\Http\FormRequest;

class PublicEmailSubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'full_name' => is_string($this->full_name) ? trim($this->full_name) : $this->full_name,
            'list_key' => is_string($this->list_key) ? trim($this->list_key) : $this->list_key,
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc,dns'],
            'full_name' => ['nullable', 'string', 'max:200'],
            // keep it simple for now; you can restrict allowed list_keys later via config
            'list_key' => ['nullable', 'string', 'max:50'],
        ];
    }
}
