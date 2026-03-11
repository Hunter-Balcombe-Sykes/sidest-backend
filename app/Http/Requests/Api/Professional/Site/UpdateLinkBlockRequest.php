<?php

namespace App\Http\Requests\Api\Professional\Site;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateLinkBlockRequest extends BaseFormRequest
{

    protected function prepareForValidation(): void
    {
        $routeId = $this->route('linkBlock') ?? $this->route('block');

        $this->merge([
            'id' => $routeId,
            'title' => is_string($this->title) ? trim($this->title) : $this->title,
            'url' => is_string($this->url) ? trim($this->url) : $this->url,
            'icon_key' => is_string($this->icon_key) ? trim($this->icon_key) : $this->icon_key,
        ]);

        if (is_array($this->settings ?? null)) {
            $this->merge([
                'settings' => array_merge($this->settings, [
                    'note' => is_string($this->settings['note'] ?? null)
                        ? trim($this->settings['note'])
                        : ($this->settings['note'] ?? null),
                ]),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'title' => ['sometimes','string','max:80'],
            'url' => ['sometimes','url','max:2048'],
            'icon_key' => ['nullable','string', Rule::in(config('comet.link_block_icon_keys', []))],
            'is_active' => ['sometimes','boolean'],
            'settings' => ['sometimes','array'],
            'settings.highlight' => ['sometimes','boolean'],
            'settings.note' => ['sometimes','string','max:140'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $settings = $this->input('settings');
            if (!is_array($settings)) {
                return;
            }

            $allowed = config('comet.link_block_settings_keys', []);
            $extra = array_diff(array_keys($settings), $allowed);
            if (!empty($extra)) {
                $validator->errors()->add(
                    'settings',
                    'The settings field contains unsupported keys: ' . implode(', ', $extra)
                );
            }
        });
    }
}
