<?php

namespace App\Http\Requests\Api\Internal\Embedded;

use App\Http\Requests\BaseFormRequest;

// Validates the Shopify domain-verification TXT-record payload for
// EmbeddedSetupController@provisionDomainTxt. The token is opaque to us — Shopify
// generates it and the brand pastes it into the embedded wizard.
class ProvisionDomainTxtRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'txt_value' => ['required', 'string', 'max:255'],
        ];
    }
}
