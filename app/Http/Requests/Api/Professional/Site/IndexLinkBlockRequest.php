<?php

namespace App\Http\Requests\Api\Professional\Site;

use Illuminate\Foundation\Http\FormRequest;

class IndexLinkBlockRequest extends FormRequest
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
