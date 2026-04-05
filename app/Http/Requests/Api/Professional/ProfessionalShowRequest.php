<?php

namespace App\Http\Requests\Api\Professional;

use App\Http\Requests\BaseFormRequest;

// V2: Authorizes the professional show endpoint — no input validation rules required.
class ProfessionalShowRequest extends BaseFormRequest
{

    public function rules(): array
    {
        return [];
    }
}
