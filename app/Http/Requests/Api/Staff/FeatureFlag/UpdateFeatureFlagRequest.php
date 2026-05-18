<?php

namespace App\Http\Requests\Api\Staff\FeatureFlag;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeatureFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'default_enabled' => ['sometimes', 'boolean'],
            'rollout_percent' => ['sometimes', 'integer', 'between:0,100'],
        ];
    }
}
