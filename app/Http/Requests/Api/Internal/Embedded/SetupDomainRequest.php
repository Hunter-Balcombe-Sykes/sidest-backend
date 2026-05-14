<?php

namespace App\Http\Requests\Api\Internal\Embedded;

use App\Http\Requests\BaseFormRequest;

// Validates the domain-setup payload for EmbeddedSetupController@setupDomain.
// The `subdomain` field is validated against the DNS label format but the controller
// intentionally derives the canonical subdomain from the brand's Site record — never from
// this input. The field is accepted to keep the embedded-app client contract intact.
class SetupDomainRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'oxygen_storefront_id' => ['required', 'string'],
            'subdomain' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,62}$/'],
        ];
    }
}
