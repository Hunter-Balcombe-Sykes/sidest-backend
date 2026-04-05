<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;

// V2: Validates public site lookup by subdomain — normalizes to lowercase and enforces alphanumeric-hyphen format with a 63-char limit.
class PublicSiteShowRequest extends BaseFormRequest
{

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
