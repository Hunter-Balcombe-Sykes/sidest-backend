<?php

namespace App\Http\Requests\Api;

use App\Http\Controllers\Concerns\DetectsClientInfo;
use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\NormalizesProfessionalType;
use App\Models\Core\Professional\Professional;
use Illuminate\Validation\Rule;

// V2: Validates professional onboarding/bootstrap — display name, email, phone, handle generation, and professional type normalization.
class BootstrapRequest extends BaseFormRequest
{
    use DetectsClientInfo;
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
            'handle' => ['sometimes', 'nullable', 'string', 'max:40'],
            'display_name' => ['required', 'string', 'max:80'],
            'primary_email' => [
                'required', 'email:rfc', 'max:255',
                Rule::unique('professionals', 'primary_email')->ignore($existingProfessionalId, 'id'),
            ],
            'phone' => ['required', ...$this->phoneRule()],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            // ISO 3166-1 alpha-2 only. Lower-case and whitespace are normalised
            // in prepareForValidation, so by the time we get here the value is
            // already two upper-case letters. Nullable because the CDN-header
            // fallback (also in prepareForValidation) handles the common case
            // where the frontend doesn't explicitly collect country.
            'country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'invite_token' => ['sometimes', 'nullable', 'string', 'max:80'],
            'brand_partner_professional_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('professionals', 'id')],
            'join_brand_handle' => ['sometimes', 'nullable', 'string', 'max:50'],
            'shopify_setup_token' => ['sometimes', 'nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'professional_type' => [
                ...$professionalTypeRules,
                'string',
                Rule::in(array_keys(config('partna.professional_types', []))),
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
            'last_name', 'country_code', 'timezone', 'professional_type', 'invite_token',
        ]);
        $this->sanitizeEmails(['primary_email']);

        // For brands: force handle to match display_name
        $professionalType = mb_strtolower(trim((string) ($this->professional_type ?? '')));
        $isBrand = $professionalType === 'brand';

        $handle = $this->handle;
        if ($isBrand) {
            // Brand handle always derived from display_name to prevent duplicate brand names
            $handle = $this->generateHandleFromDisplayName($this->display_name ?? '');
        } elseif (! is_string($handle) || $handle === '') {
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

        if ($this->exists('join_brand_handle')) {
            $merge['join_brand_handle'] = is_string($this->join_brand_handle)
                ? strtolower(trim($this->join_brand_handle))
                : null;
        }

        if ($this->exists('shopify_setup_token')) {
            $merge['shopify_setup_token'] = is_string($this->shopify_setup_token)
                ? trim($this->shopify_setup_token)
                : null;
        }

        // Resolve country_code: explicit request value first (uppercased),
        // then CDN header detection (Cloudflare / CloudFront / Vercel). The
        // fallback keeps non-AU affiliates from silently getting an
        // Australian Express account at Stripe onboarding time. If neither
        // source yields a valid ISO alpha-2 code, country_code stays null
        // and the Stripe createConnectAccount guard will 422 when they try
        // to connect.
        $providedCountry = is_string($this->country_code) ? strtoupper(trim($this->country_code)) : '';
        $resolvedCountry = $providedCountry !== '' ? $providedCountry : $this->detectCountryCode($this);
        if ($resolvedCountry !== null && $resolvedCountry !== '') {
            $merge['country_code'] = $resolvedCountry;
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
            $handle = $base.$attempt;
            $attempt++;
        }

        return $handle;
    }
}
