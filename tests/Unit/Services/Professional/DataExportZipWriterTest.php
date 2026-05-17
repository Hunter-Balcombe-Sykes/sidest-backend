<?php

uses(Tests\TestCase::class)->in(__FILE__);

use App\Services\Professional\DataExport\DataExportZipWriter;

afterEach(function () {
    // Clean up any test temp files
    foreach (glob(sys_get_temp_dir().'/export-*') as $f) {
        @unlink($f);
    }
});

function samplePayload(): array
{
    return [
        'metadata' => [
            'professional_id' => 'prof-1',
            'professional_handle' => 'jane',
            'exported_at' => '2026-04-25T00:00:00Z',
            'schema_version' => 1,
            'notes' => 'note',
        ],
        'profile' => ['professional' => ['id' => 'prof-1', 'handle' => 'jane']],
        'site' => ['site' => null, 'blocks' => []],
        'media' => ['site_media' => []],
        'integrations' => [],
        'customers' => [
            ['id' => 'c1', 'email' => 'a@b.com', 'phone' => null, 'full_name' => 'A B', 'source' => 'manual', 'notes' => null, 'created_at' => '2026-01-01T00:00:00Z'],
            ['id' => 'c2', 'email' => 'c@d.com', 'phone' => '+447000', 'full_name' => 'C D', 'source' => 'shopify', 'notes' => 'VIP', 'created_at' => '2026-01-02T00:00:00Z'],
        ],
        'services' => [],
        'service_categories' => [],
        'enquiries' => [
            ['id' => 'e1', 'name' => 'X', 'email' => 'x@y.com', 'phone' => null, 'subject' => 'Hi', 'message' => 'hello', 'created_at' => '2026-01-03T00:00:00Z'],
        ],
        'email_subscriptions' => [],
        'bookings' => [
            'booking_events' => [
                ['id' => 'b1', 'occurred_at' => '2026-01-04T00:00:00Z', 'status' => 'completed', 'source' => 'square', 'customer_name' => 'A', 'customer_email' => 'a@b.com', 'customer_phone' => null, 'amount_paid_cents' => 5000, 'currency_code' => 'GBP', 'created_at' => '2026-01-04T00:00:00Z'],
            ],
            'lead_submissions' => [],
        ],
        'billing' => [
            'subscription' => null,
            'commission_movements' => [],
            'commission_payouts' => [],
        ],
        'audit' => ['data_export_audit' => []],
    ];
}

it('writes a zip file containing data.json and CSVs', function () {
    $result = (new DataExportZipWriter)->write(samplePayload());

    expect($result['path'])->toBeFile();

    $zip = new ZipArchive;
    $zip->open($result['path']);

    expect($zip->locateName('data.json'))->not->toBeFalse();
    expect($zip->locateName('customers.csv'))->not->toBeFalse();
    expect($zip->locateName('enquiries.csv'))->not->toBeFalse();
    expect($zip->locateName('bookings.csv'))->not->toBeFalse();

    $zip->close();
});

it('data.json round-trips through json_decode', function () {
    $result = (new DataExportZipWriter)->write(samplePayload());

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $json = $zip->getFromName('data.json');
    $zip->close();

    $decoded = json_decode($json, true);
    expect($decoded)->not->toBeNull();
    expect($decoded['metadata']['schema_version'])->toBe(1);
    expect($decoded['customers'])->toHaveCount(2);
});

it('customers.csv row count matches record_counts.customers', function () {
    $result = (new DataExportZipWriter)->write(samplePayload());

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $csv = $zip->getFromName('customers.csv');
    $zip->close();

    $lines = array_filter(explode("\n", trim($csv)));
    // 1 header + 2 rows = 3 lines
    expect(count($lines))->toBe(3);
    expect($result['record_counts']['customers'])->toBe(2);
});

it('returns sha256 that matches re-hash of file', function () {
    $result = (new DataExportZipWriter)->write(samplePayload());

    expect($result['sha256'])->toBe(hash_file('sha256', $result['path']));
    expect(strlen($result['sha256']))->toBe(64);
});

it('returns size matching filesize', function () {
    $result = (new DataExportZipWriter)->write(samplePayload());

    expect($result['size'])->toBe(filesize($result['path']));
    expect($result['size'])->toBeGreaterThan(0);
});

it('skips CSVs for empty sections (no commission_payouts.csv when empty)', function () {
    $result = (new DataExportZipWriter)->write(samplePayload());

    $zip = new ZipArchive;
    $zip->open($result['path']);
    expect($zip->locateName('commission_payouts.csv'))->toBeFalse();
    $zip->close();
});

it('includes commission_payouts.csv when section is non-empty', function () {
    $payload = samplePayload();
    $payload['billing']['commission_payouts'] = [
        ['id' => 'p1', 'status' => 'paid', 'amount_cents' => 8000, 'created_at' => '2026-01-05T00:00:00Z'],
    ];

    $result = (new DataExportZipWriter)->write($payload);

    $zip = new ZipArchive;
    $zip->open($result['path']);
    expect($zip->locateName('commission_payouts.csv'))->not->toBeFalse();
    $zip->close();
});
