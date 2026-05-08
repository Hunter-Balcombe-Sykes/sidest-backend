<?php

namespace App\Http\Requests\Professional\Analytics;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates query params for GET /api/professional/affiliate/projections.
 *
 * `window_days` is optional. When omitted, the service picks the largest tier
 * the affiliate has enough history for (90 → 60 → 30 → 14). When provided, it
 * must be one of the allowed tier sizes — anything else is rejected with 422
 * to prevent unbounded windows from hitting the rollup table.
 */
class AffiliateProjectionsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'window_days' => ['sometimes', 'integer', 'in:14,30,60,90'],
        ];
    }

    public function messages(): array
    {
        return [
            'window_days.in' => 'window_days must be one of 14, 30, 60, or 90.',
        ];
    }
}
