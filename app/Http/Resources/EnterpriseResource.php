<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnterpriseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'handle' => $this->handle,
            'primary_email' => $this->primary_email,
            'phone' => $this->phone,
            'public_contact_email' => $this->public_contact_email,
            'public_contact_number' => $this->public_contact_number,
            'country_code' => $this->country_code,
            'timezone' => $this->timezone,
            'location_street_address' => $this->location_street_address,
            'location_city' => $this->location_city,
            'location_state' => $this->location_state,
            'location_postcode' => $this->location_postcode,
            'location_country' => $this->location_country,
            'enterprise_type' => $this->enterprise_type,
            'status' => $this->status,
            'subscription_tier' => $this->subscription_tier,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
