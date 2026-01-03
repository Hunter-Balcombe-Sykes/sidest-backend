<?php

namespace App\Http\Requests\Api\Professional;

use Illuminate\Foundation\Http\FormRequest;

class ProfessionalShowRequest extends FormRequest
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
