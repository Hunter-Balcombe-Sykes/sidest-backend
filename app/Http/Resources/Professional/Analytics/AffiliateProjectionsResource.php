<?php

namespace App\Http\Resources\Professional\Analytics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Locks the wire format for GET /affiliate/projections.
 *
 * Output discipline:
 *   - All money fields end in `_cents` and cast to int.
 *   - Counts cast to int. Per-day rates cast to float (rounded upstream to 2dp).
 *   - status ∈ {'ok', 'insufficient_data'}; when 'insufficient_data', `window` is null
 *     and `by_currency` is an empty array.
 *   - `momentum.pct_change_vs_prior_window` may be null when the prior-window run-rate is 0.
 *   - `ytd.best_month` may be null when no earnings exist YTD; partner field is 0 in that case.
 */
class AffiliateProjectionsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'as_of' => (string) ($r['as_of'] ?? ''),
            'data_history_days' => (int) ($r['data_history_days'] ?? 0),
            'status' => (string) ($r['status'] ?? 'insufficient_data'),
            'window' => $r['window'] === null ? null : [
                'days' => (int) $r['window']['days'],
                'from' => (string) $r['window']['from'],
                'to' => (string) $r['window']['to'],
            ],
            'engagement' => [
                'earning_days_count' => (int) ($r['engagement']['earning_days_count'] ?? 0),
                'active_brand_count' => (int) ($r['engagement']['active_brand_count'] ?? 0),
            ],
            'by_currency' => array_map(
                fn (array $c) => $this->shapeCurrency($c),
                $r['by_currency'] ?? []
            ),
        ];
    }

    private function shapeCurrency(array $c): array
    {
        $pct = $c['momentum']['pct_change_vs_prior_window'] ?? null;

        return [
            'currency_code' => (string) $c['currency_code'],
            'run_rate' => [
                'commission_cents_per_day' => (int) $c['run_rate']['commission_cents_per_day'],
                'orders_per_day' => (float) $c['run_rate']['orders_per_day'],
            ],
            'projections' => [
                'annual_commission_cents' => (int) $c['projections']['annual_commission_cents'],
                'year_end_commission_cents' => (int) $c['projections']['year_end_commission_cents'],
                'annual_orders' => (int) $c['projections']['annual_orders'],
                'confidence' => (string) $c['projections']['confidence'],
            ],
            'momentum' => [
                'pct_change_vs_prior_window' => $pct === null ? null : (float) $pct,
                'prior_run_rate_cents_per_day' => (int) $c['momentum']['prior_run_rate_cents_per_day'],
            ],
            'ytd' => [
                'commission_cents' => (int) $c['ytd']['commission_cents'],
                'orders_count' => (int) $c['ytd']['orders_count'],
                'best_month' => $c['ytd']['best_month'] === null ? null : (string) $c['ytd']['best_month'],
                'best_month_commission_cents' => (int) $c['ytd']['best_month_commission_cents'],
            ],
        ];
    }
}
