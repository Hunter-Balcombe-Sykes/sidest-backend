<?php

namespace App\Http\Requests\Api\Internal\Embedded;

use App\Http\Requests\BaseFormRequest;

// Validates step 2 business-detail payload for EmbeddedSetupController@saveBusinessDetails.
class SaveBusinessDetailsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'legal_business_name' => ['required', 'string', 'max:255'],
            'abn' => ['required', 'string', 'max:14'],
            'business_type' => ['required', 'string', 'max:100'],
            'industries' => ['required', 'array'],
            'industries.*' => ['string', 'max:100'],
        ];
    }
}
