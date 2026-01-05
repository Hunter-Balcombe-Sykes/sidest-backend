<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class BootstrapRequest extends BaseFormRequest
{


    public function rules(): array
    {
        $pro = $this->attributes->get('professional');
        $proId = $pro?->id;

        return [
            'handle' => ['required','string','max:40'],
            'display_name' => ['required','string','max:80'],
            'primary_email' => ['required','email','max:255'],
            'phone' => ['required','string','max:40'],
            'first_name' => ['required','string','max:80'],
            'last_name' => ['nullable','string','max:80'],
            'country_code' => ['nullable','string','max:5'],
            'timezone' => ['nullable','string','max:64'],
            'handle_lc' => [
                'required',
                'string',
                'max:50',
                Rule::unique('core.professionals', 'handle_lc')->ignore($proId, 'id'),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings([
                'handle', 'display_name', 'phone', 'first_name',
                'last_name', 'country_code', 'timezone'
            ]);
        $this->sanitizeEmails(['primary_email']);
        $this->merge([
            'handle_lc' => is_string($this->handle) ? strtolower(trim($this->handle)) : null,
        ]);
    }
}
