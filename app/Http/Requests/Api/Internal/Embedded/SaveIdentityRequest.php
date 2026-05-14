<?php

namespace App\Http\Requests\Api\Internal\Embedded;

use App\Http\Requests\BaseFormRequest;

// Validates step 1 brand-identity payload for EmbeddedSetupController@saveIdentity.
// All fields are optional ('sometimes') because the wizard saves partial updates.
class SaveIdentityRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'email', 'max:255'],
            'contact_number' => ['sometimes', 'string', 'max:50'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:512'],
        ];
    }
}
