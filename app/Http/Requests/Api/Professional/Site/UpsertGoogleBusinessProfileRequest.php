<?php

namespace App\Http\Requests\Api\Professional\Site;

use App\Http\Requests\BaseFormRequest;

// V2: Validates Google Business Profile upsert — place ID, name, address, coordinates, phone, website, and hours array.
class UpsertGoogleBusinessProfileRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $hours = $this->input('hours');
        if (is_array($hours)) {
            $hours = array_values(array_filter(array_map(function ($value) {
                return is_string($value) ? trim($value) : '';
            }, $hours), fn ($value) => $value !== ''));
        } else {
            $hours = [];
        }

        $trimmed = [];
        foreach (['place_id', 'name', 'address', 'phone', 'website'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $value = trim($value);
                $trimmed[$key] = $value !== '' ? $value : null;
            }
        }

        $this->merge(array_merge($trimmed, ['hours' => $hours]));
    }

    public function rules(): array
    {
        return [
            'place_id' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', ...$this->phoneRule()],
            'website' => ['nullable', 'url', 'max:2048'],
            'hours' => ['sometimes', 'array', 'max:14'],
            'hours.*' => ['string', 'max:120'],
        ];
    }
}
