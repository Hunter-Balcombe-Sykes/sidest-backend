<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ResolvesPublicSiteSubdomain;

// V2: Validates public site lookup by subdomain — normalizes to lowercase and enforces alphanumeric-hyphen format with a 63-char limit.
class PublicSiteShowRequest extends BaseFormRequest
{
    use ResolvesPublicSiteSubdomain;

    protected function prepareForValidation(): void
    {
        $this->mergeSubdomainFromRoute(); // route-only: no header fallback
    }

    public function rules(): array
    {
        return [
            'subdomain' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9-]+$/i'],
        ];
    }
}
