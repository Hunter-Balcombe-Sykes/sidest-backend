<?php

namespace App\Http\Requests\Api\PublicSite\Booking;

use App\Http\Requests\BaseFormRequest;

// V2: Validates public booking checkout — requires service variation, team member, start time, and customer contact details with optional payment source.
class PublicBookingCheckoutRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'serviceVariationId' => ['required', 'string', 'max:120'],
            'serviceVariationVersion' => ['required', 'integer', 'min:1'],
            'teamMemberId' => ['required', 'string', 'max:120'],
            'durationMinutes' => ['nullable', 'integer', 'min:1'],
            'startAt' => ['required', 'string', 'max:80'],
            'locationId' => ['nullable', 'string', 'max:120'],
            'paymentMethod' => ['nullable', 'string', 'in:apple_pay,google_pay,card'],
            'sourceId' => ['nullable', 'string', 'max:255'],
            'customer' => ['required', 'array'],
            'customer.firstName' => ['required', 'string', 'max:120'],
            'customer.lastName' => ['required', 'string', 'max:120'],
            'customer.email' => ['required', 'email:rfc', 'max:190'],
            'customer.phone' => ['nullable', 'string', 'max:60'],
            'customer.note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
