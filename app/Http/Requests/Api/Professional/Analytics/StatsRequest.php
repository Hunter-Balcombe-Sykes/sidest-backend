<?php

namespace App\Http\Requests\Api\Professional\Analytics;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StatsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['brand', 'affiliate'])],
        ];
    }
}
