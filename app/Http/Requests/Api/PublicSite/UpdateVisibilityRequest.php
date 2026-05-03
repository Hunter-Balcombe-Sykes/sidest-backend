<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;

// V2: Validates site visibility toggle — requires a single boolean published flag.
class UpdateVisibilityRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'published' => ['required', 'boolean'],
        ];
    }
}
