<?php

namespace App\Http\Requests\Api\Public\CustomerLeads;

use Illuminate\Foundation\Http\FormRequest;

class PublicCustomerLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public endpoint, no login required
        return true;
    }

    protected function prepareForValidation(): void
    {
        $optIn = $this->input('marketing_opt_in');

        $parsed = filter_var($optIn, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $this->merge([
            'full_name' => is_string($this->full_name) ? trim($this->full_name) : $this->full_name,
            'email'     => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'phone'     => is_string($this->phone) ? trim($this->phone) : $this->phone,
            'notes'     => is_string($this->notes) ? trim($this->notes) : $this->notes,
            'marketing_opt_in' => $parsed ?? false,

            // honeypot
            'website'   => is_string($this->website) ? trim($this->website) : $this->website,
        ]);
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'notes'       => ['nullable', 'string', 'max:2000'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'marketing_opt_in' => ['boolean'],

            // bot protection
            'website' => ['nullable', 'string', 'max:255'],          // honeypot
            'form_started_at_ms' => ['required', 'integer', 'min:0'],// timing check (epoch ms)
        ];
    }

}
