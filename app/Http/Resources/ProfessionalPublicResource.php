<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Public-safe shape for Professional — only fields appropriate for unauthenticated visitors.
// Excludes: auth_user_id, primary_email, phone, street address, internal status/onboarding fields.
class ProfessionalPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'handle' => $this->handle,
            'handle_lc' => $this->handle_lc,
            'display_name' => $this->display_name,
            'bio' => $this->bio,
            'professional_type' => $this->professional_type,
            'public_contact_number' => $this->public_contact_number,
            'public_contact_email' => $this->public_contact_email,
            'location_city' => $this->location_city,
            'location_state' => $this->location_state,
            'location_country' => $this->location_country,
        ];
    }
}
