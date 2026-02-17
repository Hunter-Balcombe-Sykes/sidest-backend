<?php

namespace App\Http\Requests\Api\Staff\ProfessionalSite\Services;

use App\Http\Requests\BaseFormRequest;

class StaffStoreServiceRequest extends BaseFormRequest
{

    public function rules(): array
    {
        return [
            'title'            => ['required', 'string', 'max:255'],
            'category_id'      => ['nullable', 'uuid', 'exists:service_categories,id'],
            'description'      => ['nullable', 'string', 'max:2000'],
            'price_cents'      => ['required', 'integer', 'min:0'],
            'currency_code'    => ['nullable', 'string', 'size:3'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active'        => ['sometimes', 'boolean'],
        ];
    }
}
