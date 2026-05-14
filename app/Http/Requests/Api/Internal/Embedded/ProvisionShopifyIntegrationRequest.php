<?php

namespace App\Http\Requests\Api\Internal\Embedded;

use App\Http\Requests\BaseFormRequest;

// Validates the Shopify provision-integration payload for
// EmbeddedSetupController@provisionShopifyIntegration. Called from the embedded
// app on every admin page load (token refresh path), so this must accept the same
// shape every time without forcing the optional shop_id/scopes fields.
class ProvisionShopifyIntegrationRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'access_token' => ['required', 'string', 'max:512'],
            'shop_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'scopes' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ];
    }
}
