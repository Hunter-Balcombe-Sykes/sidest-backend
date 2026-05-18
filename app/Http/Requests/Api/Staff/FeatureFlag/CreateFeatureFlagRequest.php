<?php

namespace App\Http\Requests\Api\Staff\FeatureFlag;

use Illuminate\Foundation\Http\FormRequest;

class CreateFeatureFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy enforced at controller
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'min:1', 'max:128', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:core.feature_flags,key'],
            'description' => ['nullable', 'string', 'max:500'],
            'default_enabled' => ['required', 'boolean'],
            'rollout_percent' => ['required', 'integer', 'between:0,100'],
        ];
    }
}
