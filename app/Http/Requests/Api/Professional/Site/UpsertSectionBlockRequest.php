<?php

namespace App\Http\Requests\Api\Professional\Site;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\MaxWords;
use Illuminate\Validation\Rule;


class UpsertSectionBlockRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $type = (string) $this->input('block_type');
        $allowed = config('comet.section_block_types', []);

        $textRules = ['sometimes', 'nullable', 'string', 'max:4000'];

        // Enforce 200 words for these section types
        if (in_array($type, ['bio', 'promotional_text'], true)) {
            $textRules[] = new MaxWords(200);
        }

        return [
            'block_type' => ['required', 'string', Rule::in($allowed)],
            'title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.text' => $textRules,
        ];
    }


    protected function prepareForValidation(): void
    {
        $blockType = $this->route('blockType') ?? $this->route('block_type') ?? $this->route('type');
        if (is_string($blockType)) {
            $this->merge(['block_type' => strtolower(trim($blockType))]);
        }

        // normalize text
        if (is_string(data_get($this->input('settings', []), 'text'))) {
            $t = trim((string) data_get($this->input('settings'), 'text'));
            $settings = $this->input('settings', []);
            $settings['text'] = ($t === '') ? null : $t;
            $this->merge(['settings' => $settings]);
        }
    }
}
