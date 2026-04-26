<?php

namespace App\Http\Requests\Professional;

use Illuminate\Foundation\Http\FormRequest;

// V2: Currently empty body; placeholder for future filtered/partial exports
// (e.g. ?include=customers,bookings). Keeping the class lets us evolve later
// without refactoring the controller signature.
class RequestDataExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
