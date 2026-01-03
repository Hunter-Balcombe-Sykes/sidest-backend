<?php

namespace App\Http\Requests\Api\PublicSite;

use Illuminate\Foundation\Http\FormRequest;

class PublicSiteShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $subdomain = $this->route('subdomain');

        $this->merge([
            'subdomain' => is_string($subdomain) ? strtolower($subdomain) : $subdomain,
        ]);
    }

    public function rules(): array
    {
        return [
            'subdomain' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9-]+$/i'],
        ];
    }
}
