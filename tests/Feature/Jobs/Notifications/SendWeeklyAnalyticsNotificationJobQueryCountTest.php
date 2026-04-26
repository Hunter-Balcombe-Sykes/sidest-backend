<?php

use App\Jobs\Notifications\SendWeeklyAnalyticsNotificationJob;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.professional_metrics_daily (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT,
        day TEXT,
        orders_count INTEGER,
        gross_cents INTEGER,
        refunded_cents INTEGER,
        net_cents INTEGER,
        commission_accrued_cents INTEGER,
        commission_reversed_cents INTEGER,
        commission_paid_cents INTEGER,
        currency_code TEXT
    )');
});

it('issues at most one metrics query per chunk regardless of professional count', function () {
    $weekStart = now()->subDays(7)->toDateString();
    $weekEnd = now()->subDay()->toDateString();
    $now = now()->toDateTimeString();

    // Seed 5 active professionals, each with one metrics row in the test window.
    $ids = [];
    foreach (range(1, 5) as $i) {
        $id = (string) Str::uuid();
        $ids[] = $id;

        DB::connection('pgsql')->table('core.professionals')->insert([
            'id' => $id,
            'handle' => "pro{$i}",
            'handle_lc' => "pro{$i}",
            'display_name' => "Pro {$i}",
            'primary_email' => "pro{$i}@example.test",
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::connection('pgsql')->table('analytics.professional_metrics_daily')->insert([
            'id' => (string) Str::uuid(),
            'affiliate_professional_id' => $id,
            'day' => $weekStart,
            'orders_count' => $i,
            'commission_accrued_cents' => $i * 1000,
        ]);
    }

    // Mock publisher so notifyProfessional() doesn't need notifications.notifications table.
    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->times(5);

    DB::enableQueryLog();

    (new SendWeeklyAnalyticsNotificationJob)->handle($publisher);

    $metricsQueries = array_filter(
        DB::getQueryLog(),
        fn ($q) => str_contains($q['query'], 'professional_metrics_daily')
    );

    expect(count($metricsQueries))->toBeLessThanOrEqual(1);
});
