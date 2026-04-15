<?php

namespace App\Http\Requests\Api\Professional\Uploads;

use App\Http\Requests\BaseFormRequest;

// V2: Validates a brand placeholder reorder payload — list of placeholder
// media ids in the desired order. The controller verifies they all belong to
// the brand's site before applying.
class ReorderBrandPlaceholdersRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'ordered_ids' => ['required', 'array', 'min:1', 'max:5'],
            'ordered_ids.*' => ['required', 'string', 'uuid'],
        ];
    }
}
