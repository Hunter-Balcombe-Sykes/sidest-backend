<?php

namespace App\Http\Requests\Api\Professional\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'full_name'  => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'source' => ['nullable', 'string', 'max:225'],
            'external_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'source' => $this->input('source', 'manual'),
        ]);

        $phone = $this->input('phone');
        if (is_string($phone)) {
            $phone = trim($phone);
            $phone = preg_replace('/[^\d+]/', '', $phone); // keep digits and +
            $this->merge(['phone' => $phone === '' ? null : $phone]);
        }

    }


}
