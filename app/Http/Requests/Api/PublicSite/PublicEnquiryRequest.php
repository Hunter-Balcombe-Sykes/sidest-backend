<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;

// V2: Validates public contact form submissions — name, email, phone, subject, message, with honeypot + timing bot protection. Subject allowlist is enforced by the controller against the merged platform-defaults + affiliate additions list.
class PublicEnquiryRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim(strip_tags((string) $this->name)) : $this->name,
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'phone' => is_string($this->phone) ? trim(strip_tags((string) $this->phone)) : $this->phone,
            'subject' => is_string($this->subject) ? trim($this->subject) : $this->subject,
            'message' => is_string($this->message) ? trim(strip_tags((string) $this->message)) : $this->message,
            // honeypot — must stay a string so the controller's is_string + trim check works
            'website' => is_string($this->website) ? trim($this->website) : $this->website,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', ...$this->phoneRule()],
            'subject' => ['required', 'string', 'max:100'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],

            // Bot protection (same pattern as PublicCustomerLeadRequest)
            'website' => ['nullable', 'string', 'max:255'],
            'form_started_at_ms' => ['required', 'integer', 'min:0'],
        ];
    }
}
