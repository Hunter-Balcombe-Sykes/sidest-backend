<?php

use App\Enums\BrandStatus;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\Brand\BrandStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Covers the audit-table hardening migration (20260513200000_harden_audit_tables.sql)
// — every row inserted into core.brand_status_history must carry a frozen handle
// snapshot so the row remains forensically useful after a professional is
// hard-deleted (FK is ON DELETE SET NULL).

beforeEach(function () {
    setupProfessionalsTable();
    setupBrandProfilesTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.brand_status_history (
        id TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(16)))),
        professional_id TEXT NULL,
        professional_handle_snapshot TEXT NULL,
        from_status TEXT NULL,
        to_status TEXT NULL,
        reason TEXT NULL,
        metadata TEXT NULL,
        created_at TEXT NULL
    )');
});

it('writes professional_handle_snapshot on every brand_status_history row', function () {
    $proId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'snapshot-handle',
        'handle_lc' => 'snapshot-handle',
        'display_name' => 'Snapshot Brand',
        'primary_email' => 'snapshot@example.test',
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('brand.brand_profiles')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'brand_status' => BrandStatus::Onboarding->value,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $professional = Professional::query()->findOrFail($proId);

    // Partial-mock determine() so we don't need Shopify/Stripe state — the test
    // is about the audit writer, not the status decision tree.
    $service = Mockery::mock(BrandStatusService::class)->makePartial();
    $service->shouldReceive('determine')
        ->once()
        ->andReturn(BrandStatus::ShopifyLinked);

    $service->sync($professional);

    $row = DB::connection('pgsql')->table('core.brand_status_history')
        ->where('professional_id', $proId)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->professional_handle_snapshot)->toBe('snapshot-handle')
        ->and($row->from_status)->toBe(BrandStatus::Onboarding->value)
        ->and($row->to_status)->toBe(BrandStatus::ShopifyLinked->value);
});
