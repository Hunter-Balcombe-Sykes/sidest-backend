<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// V2: Response shape for POST /store/shopify/resync. Only exposes field names + counts + a timestamp —
//     never leaks the snapshot contents, access tokens, or shop identifiers.
class ShopifyResyncResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : [];

        return [
            'fields_updated' => is_array($data['fields_updated'] ?? null) ? array_values($data['fields_updated']) : [],
            'fields_preserved' => is_array($data['fields_preserved'] ?? null) ? array_values($data['fields_preserved']) : [],
            'jobs_dispatched' => is_array($data['jobs_dispatched'] ?? null) ? array_values($data['jobs_dispatched']) : [],
            'last_resynced_at' => $data['last_resynced_at'] ?? null,
        ];
    }
}
