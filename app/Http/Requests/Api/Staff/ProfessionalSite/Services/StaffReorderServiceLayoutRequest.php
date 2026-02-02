<?php

namespace App\Http\Requests\Api\Staff\ProfessionalSite\Services;

use App\Http\Requests\BaseFormRequest;

class StaffReorderServiceLayoutRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'categories' => ['required', 'array'],

            'categories.*.id' => ['nullable', 'uuid'], // null = Uncategorized bucket
            'categories.*.service_ids' => ['required', 'array'],
            'categories.*.service_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
