<?php

namespace App\Http\Requests\Api\Staff\FeatureFlag;

use Illuminate\Foundation\Http\FormRequest;

class CreateOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'professional_id' => ['required_without:brand_id', 'nullable', 'uuid', 'exists:core.professionals,id'],
            'brand_id' => ['required_without:professional_id', 'nullable', 'uuid', 'exists:brand.brand_profiles,id'],
            'enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->filled('professional_id') && $this->filled('brand_id')) {
                $v->errors()->add('scope', 'Provide professional_id or brand_id, not both.');
            }
        });
    }
}
