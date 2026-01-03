<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class BootstrapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // JWT middleware already authenticated them
    }

    public function rules(): array
    {
        return [
            'handle' => ['required','string','max:40'],
            'display_name' => ['required','string','max:80'],
            'primary_email' => ['required','email','max:255'],
            'phone' => ['required','string','max:40'],
            'first_name' => ['required','string','max:80'],
            'last_name' => ['nullable','string','max:80'],
            'country_code' => ['nullable','string','max:5'],
            'timezone' => ['nullable','string','max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'handle' => is_string($this->handle) ? trim($this->handle) : $this->handle,
            'display_name' => is_string($this->display_name) ? trim($this->display_name) : $this->display_name,
            'primary_email' => is_string($this->primary_email) ? strtolower(trim($this->primary_email)) : $this->primary_email,
            'phone' => is_string($this->phone) ? trim($this->phone) : $this->phone,
            'first_name' => is_string($this->first_name) ? trim($this->first_name) : $this->first_name,
            'last_name' => is_string($this->last_name) ? trim($this->last_name) : $this->last_name,
            'country_code' => is_string($this->country_code) ? trim($this->country_code) : $this->country_code,
            'timezone' => is_string($this->timezone) ? trim($this->timezone) : $this->timezone,
        ]);
    }
}
