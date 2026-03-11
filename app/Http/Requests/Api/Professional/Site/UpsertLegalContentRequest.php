<?php

namespace App\Http\Requests\Api\Professional\Site;

use App\Http\Requests\BaseFormRequest;
use App\Models\Core\Professional\ProfessionalLegalContent;
use Illuminate\Validation\Rule;

class UpsertLegalContentRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('active_privacy_source')) {
            $merge['active_privacy_source'] = mb_strtolower(trim((string) $this->input('active_privacy_source')));
        }

        if ($this->has('active_terms_source')) {
            $merge['active_terms_source'] = mb_strtolower(trim((string) $this->input('active_terms_source')));
        }

        if ($this->has('manual_privacy_policy') && is_string($this->input('manual_privacy_policy'))) {
            $merge['manual_privacy_policy'] = trim((string) $this->input('manual_privacy_policy'));
        }

        if ($this->has('manual_terms_and_conditions') && is_string($this->input('manual_terms_and_conditions'))) {
            $merge['manual_terms_and_conditions'] = trim((string) $this->input('manual_terms_and_conditions'));
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'manual_privacy_policy' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'manual_terms_and_conditions' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'active_privacy_source' => [
                'sometimes',
                'string',
                Rule::in([
                    ProfessionalLegalContent::SOURCE_TEMPLATED,
                    ProfessionalLegalContent::SOURCE_MANUAL,
                ]),
            ],
            'active_terms_source' => [
                'sometimes',
                'string',
                Rule::in([
                    ProfessionalLegalContent::SOURCE_TEMPLATED,
                    ProfessionalLegalContent::SOURCE_MANUAL,
                ]),
            ],
            'regenerate_templated' => ['sometimes', 'boolean'],
        ];
    }
}
