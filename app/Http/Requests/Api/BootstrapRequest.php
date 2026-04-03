<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\NormalizesProfessionalType;
use App\Models\Core\Professional\Professional;
use Illuminate\Validation\Rule;

class BootstrapRequest extends BaseFormRequest
{
    use NormalizesProfessionalType;


    public function rules(): array
    {
        $uid = $this->attributes->get('supabase_uid');

        $existingProfessionalId = null;
        if (is_string($uid) && $uid !== '') {
            $existingProfessionalId = Professional::query()
                ->where('auth_user_id', $uid)
                ->value('id');
        }

        $professionalTypeRules = is_string($existingProfessionalId) && $existingProfessionalId !== ''
            ? ['sometimes', 'required']
            : ['required'];

        return [
            'handle' => ['sometimes','nullable','string','max:40'],
            'display_name' => ['required','string','max:80'],
            'primary_email' => [
                'required','email','max:255',
                Rule::unique('professionals', 'primary_email')->ignore($existingProfessionalId, 'id'),
            ],
            'phone' => ['required','string','max:40'],
            'first_name' => ['required','string','max:80'],
            'last_name' => ['nullable','string','max:80'],
            'country_code' => ['nullable','string','max:5'],
            'timezone' => ['nullable','string','max:64'],
            'invite_token' => ['sometimes', 'nullable', 'string', 'max:80'],
            'brand_partner_professional_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('professionals', 'id')],
            'professional_type' => [
                ...$professionalTypeRules,
                'string',
                Rule::in(array_keys(config('sidest.professional_types', []))),
            ],
            'handle_lc' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('professionals', 'handle_lc')->ignore($existingProfessionalId, 'id'),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings([
                'handle', 'display_name', 'phone', 'first_name',
                'last_name', 'country_code', 'timezone', 'professional_type', 'invite_token'
            ]);
        $this->sanitizeEmails(['primary_email']);

        // For brands: force handle to match display_name
        $professionalType = mb_strtolower(trim((string) ($this->professional_type ?? '')));
        $isBrand = $professionalType === 'brand';

        $handle = $this->handle;
        if ($isBrand) {
            // Brand handle always derived from display_name to prevent duplicate brand names
            $handle = $this->generateHandleFromDisplayName($this->display_name ?? '');
        } elseif (!is_string($handle) || $handle === '') {
            $handle = $this->generateHandleFromDisplayName($this->display_name ?? '');
        }

        $merge = [
            'handle' => $handle,
            'handle_lc' => is_string($handle) ? strtolower(trim($handle)) : null,
        ];

        if ($this->exists('professional_type')) {
            $merge['professional_type'] = $this->normalizeProfessionalTypeInput($this->professional_type);
        }

        if ($this->exists('invite_token')) {
            $merge['invite_token'] = is_string($this->invite_token) ? trim($this->invite_token) : null;
        }

        if ($this->exists('brand_partner_professional_id')) {
            $merge['brand_partner_professional_id'] = is_string($this->brand_partner_professional_id)
                ? trim($this->brand_partner_professional_id)
                : null;
        }

        $this->merge($merge);
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
