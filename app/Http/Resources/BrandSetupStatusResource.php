<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandSetupStatusResource extends JsonResource
{
    /**
     * @param Request $request
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
