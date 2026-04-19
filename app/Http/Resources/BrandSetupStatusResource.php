<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// V2: API resource for brand setup wizard status — exposes completion flag, field states, and missing fields.
class BrandSetupStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'setup_complete' => $this->resource['setup_complete'],
            'fields' => $this->resource['fields'],
            'missing_fields' => $this->resource['missing_fields'],
        ];
    }
}
