<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class StaffInitiateDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by staff.admin middleware
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'override_obligations' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Reason must be at least 10 characters — record the support ticket reference and the article cited.',
            'reason.max' => 'Reason must be 500 characters or fewer.',
        ];
    }
}
