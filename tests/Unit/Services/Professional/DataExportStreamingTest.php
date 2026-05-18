<?php

uses(Tests\TestCase::class)->in(__FILE__);

use App\Services\Professional\DataExport\DataExportPayloadBuilder;
use App\Services\Professional\DataExport\DataExportZipWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Professional\DataExport\DataExportTestCase;

beforeEach(function () {
    DataExportTestCase::boot();
});

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/export-*') as $f) {
        @unlink($f);
    }
});

function seedStreamingProfessional(string $id): void
{
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'jane',
        'handle_lc' => 'jane',
        'display_name' => 'Jane',
        'primary_email' => 'jane@example.com',
        'status' => 'active',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);
}

it('writeStreaming produces a zip with a parseable data.json', function () {
    $profId = (string) Str::uuid();
    seedStreamingProfessional($profId);

    DB::connection('pgsql')->table('core.customers')->insert([
        ['id' => (string) Str::uuid(), 'professional_id' => $profId, 'email' => 'c1@x.com', 'full_name' => 'C1', 'created_at' => '2026-01-01T00:00:00Z'],
        ['id' => (string) Str::uuid(), 'professional_id' => $profId, 'email' => 'c2@x.com', 'full_name' => 'C2', 'created_at' => '2026-01-02T00:00:00Z'],
    ]);

    $result = (new DataExportZipWriter)->writeStreaming(new DataExportPayloadBuilder, $profId);

    expect($result['path'])->toBeFile();

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $json = $zip->getFromName('data.json');
    $zip->close();

    $decoded = json_decode($json, true);
    expect($decoded)->not->toBeNull('data.json must be valid JSON');
    expect($decoded['metadata']['schema_version'])->toBe(1);
    expect($decoded['metadata']['professional_id'])->toBe($profId);
    expect($decoded['customers'])->toHaveCount(2);
    expect($result['record_counts']['customers'])->toBe(2);
});

it('writeStreaming emits customers.csv with one row per customer', function () {
    $profId = (string) Str::uuid();
    seedStreamingProfessional($profId);

    DB::connection('pgsql')->table('core.customers')->insert([
        ['id' => (string) Str::uuid(), 'professional_id' => $profId, 'email' => 'a@x.com', 'full_name' => 'A', 'created_at' => '2026-01-01T00:00:00Z'],
        ['id' => (string) Str::uuid(), 'professional_id' => $profId, 'email' => 'b@x.com', 'full_name' => 'B', 'created_at' => '2026-01-02T00:00:00Z'],
        ['id' => (string) Str::uuid(), 'professional_id' => $profId, 'email' => 'c@x.com', 'full_name' => 'C', 'created_at' => '2026-01-03T00:00:00Z'],
    ]);

    $result = (new DataExportZipWriter)->writeStreaming(new DataExportPayloadBuilder, $profId);

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $csv = $zip->getFromName('customers.csv');
    $zip->close();

    $lines = array_filter(explode("\n", trim($csv)));
    // 1 header + 3 rows
    expect(count($lines))->toBe(4);
    expect($result['record_counts']['customers'])->toBe(3);
});

it('writeStreaming produces nested bookings group with booking_events array', function () {
    $profId = (string) Str::uuid();
    seedStreamingProfessional($profId);

    DB::connection('pgsql')->table('analytics.booking_events')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $profId,
        'occurred_at' => '2026-01-04T00:00:00Z',
        'status' => 'completed',
        'source' => 'square',
        'customer_name' => 'A',
        'customer_email' => 'a@x.com',
        'amount_paid_cents' => 5000,
        'currency_code' => 'GBP',
        'raw_payload' => '{"secret":1}',
        'created_at' => '2026-01-04T00:00:00Z',
    ]);

    $result = (new DataExportZipWriter)->writeStreaming(new DataExportPayloadBuilder, $profId);

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $json = $zip->getFromName('data.json');
    $bookingsCsv = $zip->getFromName('bookings.csv');
    $zip->close();

    $decoded = json_decode($json, true);
    expect($decoded['bookings']['booking_events'])->toHaveCount(1);
    expect($decoded['bookings']['booking_events'][0])->not->toHaveKey('raw_payload');
    expect($decoded['bookings']['lead_submissions'])->toBe([]);
    expect($bookingsCsv)->not->toBeFalse();
    expect($result['record_counts']['booking_events'])->toBe(1);
});

it('writeStreaming produces an empty-but-valid zip for a professional with no related data', function () {
    $profId = (string) Str::uuid();
    seedStreamingProfessional($profId);

    $result = (new DataExportZipWriter)->writeStreaming(new DataExportPayloadBuilder, $profId);

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $json = $zip->getFromName('data.json');
    expect($zip->locateName('customers.csv'))->toBeFalse();
    $zip->close();

    $decoded = json_decode($json, true);
    expect($decoded['customers'])->toBe([]);
    expect($decoded['bookings']['booking_events'])->toBe([]);
    expect($decoded['billing']['subscription'])->toBeNull();
    expect($result['record_counts']['customers'])->toBe(0);
});
