<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AffiliateProductSelectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_gid' => $this->shopify_product_gid,
            'sort_order' => (int) $this->sort_order,
            // NULL = show every brand-enabled variant (default). Populated array =
            // affiliate has narrowed to this specific subset. Surface to the UI so
            // the variant picker can reflect current state.
            'selected_variant_gids' => $this->selected_variant_gids,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
