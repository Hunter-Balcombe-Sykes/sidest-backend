<?php

namespace App\Http\Requests\Api\Staff;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// V2: Validates the `send_to` query param on staff-triggered data exports.
// Default is 'professional' (the safer mode). 'staff' requires admin role —
// enforced in the controller, not here.
class RequestStaffDataExportRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'send_to' => $this->query('send_to', 'professional'),
        ]);
    }

    public function rules(): array
    {
        return [
            'send_to' => ['required', Rule::in(['professional', 'staff'])],
        ];
    }
}
