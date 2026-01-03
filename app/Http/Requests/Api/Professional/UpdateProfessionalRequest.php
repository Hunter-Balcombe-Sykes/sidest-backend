<?php

namespace App\Http\Requests\Api\Professional;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateProfessionalRequest extends BaseFormRequest
{

    public function rules(): array
    {
        $professional = $this->attributes->get('professional');
        $proId = $professional?->id;

        $bucket = (string) config('comet.media_bucket', 'media');

        $iconPrefix = $proId ? "professionals/{$proId}/icon." : 'professionals/';
        $headshotPrefix = $proId ? "professionals/{$proId}/headshot." : 'professionals/';

        return [
            // keep handle out of this endpoint (handle changes should be a dedicated flow)
            'display_name'  => ['sometimes', 'required', 'string', 'max:255'],
            'bio'           => ['sometimes', 'nullable', 'string', 'max:2000'],

            'first_name'    => ['sometimes', 'required', 'string', 'max:255'],
            'last_name'     => ['sometimes', 'nullable', 'string', 'max:255'],

            'primary_email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone'         => ['sometimes', 'required', 'string', 'max:50'],
            'public_contact_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'public_contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],

            'country_code'  => ['sometimes', 'nullable', 'string', 'min:2', 'max:3'],
            'timezone'      => ['sometimes', 'nullable', 'string', 'max:64'],

            // Images (STRICT)
            'icon_bucket' => ['sometimes', 'nullable', 'string', 'max:255', Rule::in([$bucket])],
            'icon_path' => [
                'sometimes', 'nullable', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($iconPrefix) {
                    if ($value === null) return;

                    if (!is_string($value) || !str_starts_with($value, $iconPrefix)) {
                        $fail('Invalid icon_path: must match your prepared upload path.');
                        return;
                    }

                    if (!preg_match('/\.(jpg|png|webp)$/i', $value)) {
                        $fail('Invalid icon_path extension.');
                    }
                },
            ],

            'headshot_bucket' => ['sometimes', 'nullable', 'string', 'max:255', Rule::in([$bucket])],
            'headshot_path' => [
                'sometimes', 'nullable', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($headshotPrefix) {
                    if ($value === null) return;

                    if (!is_string($value) || !str_starts_with($value, $headshotPrefix)) {
                        $fail('Invalid headshot_path: must match your prepared upload path.');
                        return;
                    }

                    if (!preg_match('/\.(jpg|png|webp)$/i', $value)) {
                        $fail('Invalid headshot_path extension.');
                    }
                },
            ],

            // Location
            'location_street_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_postcode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_country' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');
        if (is_string($phone)) {
            $phone = trim($phone);
            $phone = preg_replace('/[^\d+]/', '', $phone); // keep digits and +
            $this->merge(['phone' => $phone === '' ? null : $phone]);
        }
        $public = $this->input('public_contact_number');
        if (is_string($public)) {
            $public = trim($public);
            $public = preg_replace('/[^\d+]/', '', $public); // keep digits and +
            $this->merge(['public_contact_number' => $public === '' ? null : $public]);
        }

        // trim paths/buckets so "" becomes null instead of weirdness
        foreach (['icon_bucket','icon_path','headshot_bucket','headshot_path'] as $k) {
            if ($this->has($k) && is_string($this->input($k))) {
                $v = trim($this->input($k));
                $this->merge([$k => $v === '' ? null : $v]);
            }
        }

        $merge = [];

        if ($this->has('primary_email')) {
            $merge['primary_email'] = $this->lowerOrNull($this->input('primary_email'));
        }

        if ($this->has('public_contact_email')) {
            $merge['public_contact_email'] = $this->lowerOrNull($this->input('public_contact_email'));
        }

        if ($merge) {
            $this->merge($merge);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->enforcePair($validator, 'icon_bucket', 'icon_path');
            $this->enforcePair($validator, 'headshot_bucket', 'headshot_path');
        });
    }

    private function enforcePair($validator, string $bucketKey, string $pathKey): void
    {
        $bucketProvided = $this->has($bucketKey);
        $pathProvided   = $this->has($pathKey);

        // If updating either, require both keys present in the payload
        if ($bucketProvided xor $pathProvided) {
            $validator->errors()->add($bucketKey, "Provide both {$bucketKey} and {$pathKey} together.");
            $validator->errors()->add($pathKey, "Provide both {$bucketKey} and {$pathKey} together.");
            return;
        }

        // If neither provided, fine
        if (!$bucketProvided && !$pathProvided) {
            return;
        }

        $bucketVal = $this->input($bucketKey);
        $pathVal   = $this->input($pathKey);

        // Clearing must clear both
        if (($bucketVal === null) xor ($pathVal === null)) {
            $validator->errors()->add($bucketKey, "To clear, set BOTH {$bucketKey} and {$pathKey} to null.");
            $validator->errors()->add($pathKey, "To clear, set BOTH {$bucketKey} and {$pathKey} to null.");
        }
    }

    private function lowerOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        // Treat empty string as null (prevents “only one user can have empty email” style issues)
        if ($value === '') {
            return null;
        }

        return mb_strtolower($value);
    }
}
