<?php

namespace App\Http\Requests\Api\PublicSite\Booking;

use App\Http\Requests\BaseFormRequest;

// V2: Validates availability lookup for a booking slot — requires a date and service variation ID, with optional location.
class PublicBookingAvailabilityRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'serviceVariationId' => ['required', 'string', 'max:120'],
            'locationId' => ['nullable', 'string', 'max:120'],
        ];
    }
}
