<?php

uses(Tests\TestCase::class);

use App\Http\Resources\Professional\Analytics\AffiliateProjectionsResource;
use Illuminate\Http\Request;

it('shapes the happy-path payload exactly per the API contract', function () {
    $payload = [
        'as_of' => '2026-05-07T14:23:11+00:00',
        'data_history_days' => 47,
        'status' => 'ok',
        'window' => ['days' => 30, 'from' => '2026-04-07', 'to' => '2026-05-06'],
        'engagement' => ['earning_days_count' => 22, 'active_brand_count' => 2],
        'by_currency' => [
            [
                'currency_code' => 'USD',
                'run_rate' => ['commission_cents_per_day' => 4231, 'orders_per_day' => 1.2],
                'projections' => [
                    'annual_commission_cents' => 1544315,
                    'year_end_commission_cents' => 1102000,
                    'annual_orders' => 438,
                    'confidence' => 'medium',
                ],
                'momentum' => ['pct_change_vs_prior_window' => 0.23, 'prior_run_rate_cents_per_day' => 3440],
                'ytd' => [
                    'commission_cents' => 612000,
                    'orders_count' => 178,
                    'best_month' => '2026-03',
                    'best_month_commission_cents' => 184000,
                ],
            ],
        ],
    ];

    $array = (new AffiliateProjectionsResource($payload))->toArray(Request::create('/'));

    expect($array['status'])->toBe('ok');
    expect($array['by_currency'][0]['projections']['annual_commission_cents'])->toBe(1544315);
    expect($array['by_currency'][0]['projections']['confidence'])->toBe('medium');
    expect($array)->toHaveKeys(['as_of', 'data_history_days', 'status', 'window', 'engagement', 'by_currency']);
});

it('shapes the insufficient_data payload', function () {
    $payload = [
        'as_of' => '2026-05-07T14:23:11+00:00',
        'data_history_days' => 5,
        'status' => 'insufficient_data',
        'window' => null,
        'engagement' => ['earning_days_count' => 5, 'active_brand_count' => 1],
        'by_currency' => [],
    ];

    $array = (new AffiliateProjectionsResource($payload))->toArray(Request::create('/'));

    expect($array['status'])->toBe('insufficient_data');
    expect($array['window'])->toBeNull();
    expect($array['by_currency'])->toBe([]);
});
