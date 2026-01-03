<?php

namespace App\Http\Requests\Api\Professional\Site;

use App\Http\Requests\BaseFormRequest;

class DestroyLinkBlockRequest extends BaseFormRequest
{

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => $this->route('block'),
        ]);
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
        ];
    }
}
