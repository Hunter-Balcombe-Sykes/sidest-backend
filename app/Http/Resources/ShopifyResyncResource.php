<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// V2: Response shape for POST /store/shopify/resync. Only exposes field names + counts + a timestamp —
//     never leaks the snapshot contents, access tokens, or shop identifiers.
//
// Trust contract: ShopifyDataResyncService::resync() is typed to return {fields_updated: string[],
// fields_preserved: string[], jobs_dispatched: string[], last_resynced_at: string} — no defensive
// re-casting needed here.
class ShopifyResyncResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'fields_updated' => $this->resource['fields_updated'],
            'fields_preserved' => $this->resource['fields_preserved'],
            'jobs_dispatched' => $this->resource['jobs_dispatched'],
            'last_resynced_at' => $this->resource['last_resynced_at'],
        ];
    }
}
