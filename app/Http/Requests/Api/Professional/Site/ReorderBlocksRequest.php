<?php

namespace App\Http\Requests\Api\Professional\Site;

use App\Http\Requests\BaseFormRequest;

class ReorderBlocksRequest extends BaseFormRequest
{

    public function rules(): array
    {
        return [
            'ids' => ['required','array','min:1'],
            'ids.*' => ['uuid'],
        ];
    }
}
