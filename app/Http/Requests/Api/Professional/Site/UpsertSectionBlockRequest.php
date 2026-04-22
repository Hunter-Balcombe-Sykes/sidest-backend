<?php

namespace App\Http\Requests\Api\Professional\Site;

use App\Http\Requests\BaseFormRequest;
use App\Rules\MaxWords;
use Illuminate\Validation\Rule;

// V2: Validates section block upsert — block type from config allowlist, title, publication state, and word-limited text for bio/promo sections.
class UpsertSectionBlockRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $type = (string) $this->input('block_type');
        $allowed = config('sidest.section_block_types', []);

        $textRules = ['sometimes', 'nullable', 'string', 'max:4000'];

        // Enforce 200 words for these section types
        if (in_array($type, ['bio', 'promotional_text'], true)) {
            $textRules[] = new MaxWords(200);
        }

        return [
            'block_type' => ['required', 'string', Rule::in($allowed)],
            'title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
            'publication_state' => ['sometimes', 'string', Rule::in(['live', 'draft'])],
            'settings' => ['sometimes', 'array'],
            'settings.text' => $textRules,

            // Newsletter section — configurable copy for the signup form.
            // Frontend falls back to sensible defaults when any field is null,
            // so the section works out-of-the-box without configuration.
            'settings.headline' => ['sometimes', 'nullable', 'string', 'max:80'],
            'settings.description' => ['sometimes', 'nullable', 'string', 'max:200'],
            'settings.cta_label' => ['sometimes', 'nullable', 'string', 'max:40'],
            // list_key routes the signup to a named mailing list. Defaults to
            // 'marketing' server-side if omitted; constrained to slug shape so
            // a free-form value can't collide with future system list keys.
            'settings.list_key' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]{0,39}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $blockType = $this->route('blockType') ?? $this->route('block_type') ?? $this->route('type');
        if (is_string($blockType)) {
            $this->merge(['block_type' => strtolower(trim($blockType))]);
        }

        $publicationState = $this->input('publication_state');
        if (is_string($publicationState)) {
            $this->merge(['publication_state' => strtolower(trim($publicationState))]);
        }

        // normalize text — strip HTML tags before validation to prevent stored XSS
        if (is_string(data_get($this->input('settings', []), 'text'))) {
            $t = trim(strip_tags((string) data_get($this->input('settings'), 'text')));
            $settings = $this->input('settings', []);
            $settings['text'] = ($t === '') ? null : $t;
            $this->merge(['settings' => $settings]);
        }

        // Same strip_tags defense-in-depth for newsletter copy fields.
        // Frontend must still escape on render; this just prevents stored tags
        // from reaching a future buggy renderer.
        $settings = $this->input('settings', []);
        $settingsChanged = false;
        foreach (['headline', 'description', 'cta_label'] as $key) {
            if (is_string(data_get($settings, $key))) {
                $cleaned = trim(strip_tags((string) $settings[$key]));
                $settings[$key] = ($cleaned === '') ? null : $cleaned;
                $settingsChanged = true;
            }
        }
        if ($settingsChanged) {
            $this->merge(['settings' => $settings]);
        }
    }
}
