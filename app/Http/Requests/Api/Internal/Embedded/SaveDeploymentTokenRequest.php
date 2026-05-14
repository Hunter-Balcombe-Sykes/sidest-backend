<?php

namespace App\Http\Requests\Api\Internal\Embedded;

use App\Http\Requests\BaseFormRequest;

// Validates the Oxygen deployment-token payload for EmbeddedSetupController@saveDeploymentToken.
// `token` is required, `storefront_id` is optional/nullable to support callers that store the
// token before they've finished the Hydrogen storefront ID step.
class SaveDeploymentTokenRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:512'],
            'storefront_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
