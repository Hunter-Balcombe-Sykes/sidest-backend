<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\BaseFormRequest;
use App\Models\Core\Professional\Professional;
use Illuminate\Validation\Rule;

class BootstrapRequest extends BaseFormRequest
{


    public function rules(): array
    {
        $uid = $this->attributes->get('supabase_uid');

        $ignoreId = null;
        if (is_string($uid) && $uid !== '') {
            $ignoreId = Professional::query()
                ->where('auth_user_id', $uid)
                ->value('id');
        }

        return [
            'handle' => ['sometimes','nullable','string','max:40'],
            'display_name' => ['required','string','max:80'],
            'primary_email' => ['required','email','max:255'],
            'phone' => ['required','string','max:40'],
            'first_name' => ['required','string','max:80'],
            'last_name' => ['nullable','string','max:80'],
            'country_code' => ['nullable','string','max:5'],
            'timezone' => ['nullable','string','max:64'],
            'professional_type' => [
                'sometimes',
                'required',
                'string',
                Rule::in(array_keys(config('comet.professional_types', []))),
            ],
            'handle_lc' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('professionals', 'handle_lc')->ignore($ignoreId, 'id'),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings([
                'handle', 'display_name', 'phone', 'first_name',
                'last_name', 'country_code', 'timezone', 'professional_type'
            ]);
        $this->sanitizeEmails(['primary_email']);

        // Auto-generate handle from display_name if not provided
        $handle = $this->handle;
        if (!is_string($handle) || $handle === '') {
            $handle = $this->generateHandleFromDisplayName($this->display_name ?? '');
        }

        $this->merge([
            'handle' => $handle,
            'handle_lc' => is_string($handle) ? strtolower(trim($handle)) : null,
            'professional_type' => is_string($this->professional_type ?? null) && trim((string) $this->professional_type) !== ''
                ? strtolower(trim((string) $this->professional_type))
                : ($this->professional_type ?? null),
        ]);
    }

    private function generateHandleFromDisplayName(string $displayName): string
    {
        // Convert display name to slug (e.g., "Josh's Barbershop" -> "joshs-barbershop")
        $base = \Illuminate\Support\Str::slug($displayName);

        if ($base === '' || $base === '-') {
            $base = 'professional';
        }

        // Check if handle is available, if not append numbers
        $handle = $base;
        $attempt = 1;
        while (Professional::query()->where('handle_lc', strtolower($handle))->exists()) {
            $handle = $base . $attempt;
            $attempt++;
        }

        return $handle;
    }
}
