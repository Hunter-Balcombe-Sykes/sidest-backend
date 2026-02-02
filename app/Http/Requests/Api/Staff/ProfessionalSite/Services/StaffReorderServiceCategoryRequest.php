<?php

namespace App\Http\Requests\Api\Staff\ProfessionalSite\Services;

use App\Http\Requests\BaseFormRequest;

class StaffReorderServiceCategoryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
