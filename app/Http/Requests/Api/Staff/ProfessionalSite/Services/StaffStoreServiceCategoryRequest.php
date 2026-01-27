<?php

namespace App\Http\Requests\Api\Staff\ProfessionalSite\Services;

use App\Http\Requests\BaseFormRequest;

class StaffStoreServiceCategoryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title'      => ['required', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
