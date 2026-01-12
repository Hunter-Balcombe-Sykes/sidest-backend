<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;
use Faker\Provider\Base;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVisibilityRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'published' => ['required', 'boolean'],
        ];
    }
}
