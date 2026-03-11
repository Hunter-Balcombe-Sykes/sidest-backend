<?php

namespace App\Http\Requests\Api\Professional\Site;

use App\Http\Requests\BaseFormRequest;

class DestroyLinkBlockRequest extends BaseFormRequest
{

    protected function prepareForValidation(): void
    {
        $routeId = $this->route('linkBlock') ?? $this->route('block');

        $this->merge([
            'id' => $routeId,
        ]);
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
        ];
    }
}
