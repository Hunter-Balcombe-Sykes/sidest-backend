<?php

namespace App\Http\Requests\Api\Staff\ProfessionalSite\Services;

use Illuminate\Foundation\Http\FormRequest;

class StaffReorderServiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
