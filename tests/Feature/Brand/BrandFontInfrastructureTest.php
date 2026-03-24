<?php

use App\Http\Requests\Api\Professional\Site\UpdateSiteRequest;
use App\Http\Requests\Api\Professional\Uploads\UploadBrandFontRequest;
use App\Http\Requests\Api\Staff\ProfessionalSite\StaffUpdateSiteRequest;
use App\Services\Branding\BrandFontResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    config()->set('database.connections.pgsql', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('pgsql');
    DB::reconnect('pgsql');

    setupBrandFontSchema();
    Cache::flush();
    DB::connection('pgsql')->table('brand_fonts')->delete();
})->group('brand-fonts');

it('hydrates typography settings from active brand font row', function () {
    $brandId = '00000000-0000-0000-0000-000000000001';

    DB::connection('pgsql')->table('brand_fonts')->insert([
        'id' => '10000000-0000-0000-0000-000000000001',
        'brand_professional_id' => $brandId,
        'slot' => 'primary',
        'file_name' => 'LegacyFont.woff2',
        'file_path' => 'fonts/' . $brandId . '/design/legacy.woff2',
        'file_url' => 'https://cdn.example.com/fonts/legacy.woff2',
        'format' => 'woff2',
        'file_hash' => str_repeat('a', 64),
        'size_bytes' => 1024,
        'is_active' => false,
        'created_at' => '2026-03-19 09:00:00',
        'updated_at' => '2026-03-19 09:00:00',
        'deleted_at' => null,
    ]);

    DB::connection('pgsql')->table('brand_fonts')->insert([
        'id' => '20000000-0000-0000-0000-000000000001',
        'brand_professional_id' => $brandId,
        'slot' => 'primary',
        'file_name' => 'BrandFont.woff2',
        'file_path' => 'fonts/' . $brandId . '/design/brand.woff2',
        'file_url' => 'https://cdn.example.com/fonts/brand.woff2',
        'format' => 'woff2',
        'file_hash' => str_repeat('b', 64),
        'size_bytes' => 2048,
        'is_active' => true,
        'created_at' => '2026-03-19 10:00:00',
        'updated_at' => '2026-03-19 10:00:00',
        'deleted_at' => null,
    ]);

    $resolver = app(BrandFontResolver::class);

    $settings = [
        'design' => [
            'typography' => [
                'logo_font_size' => '34px',
            ],
        ],
    ];

    $hydrated = $resolver->hydrateTypographySettings($settings, $brandId);

    expect(data_get($hydrated, 'design.typography.font_file_name'))->toBe('BrandFont.woff2');
    expect(data_get($hydrated, 'design.typography.font_file_path'))->toBe('fonts/' . $brandId . '/design/brand.woff2');
    expect(data_get($hydrated, 'design.typography.font_file_url'))->toBe('https://cdn.example.com/fonts/brand.woff2');
    expect(data_get($hydrated, 'design.typography.logo_font_size'))->toBe('34px');
});

it('rejects non-woff2 signature even when extension is woff2', function () {
    $badFont = UploadedFile::fake()->createWithContent('brand.woff2', 'NOT_A_WOFF2_FONT');

    $validator = Validator::make(
        ['font' => $badFont],
        (new UploadBrandFontRequest())->rules(),
        (new UploadBrandFontRequest())->messages()
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('font'))->toBeTrue();
});

it('accepts a valid woff2 signature', function () {
    $goodFont = UploadedFile::fake()->createWithContent('brand.woff2', 'wOF2' . str_repeat('A', 200));

    $validator = Validator::make(
        ['font' => $goodFont],
        (new UploadBrandFontRequest())->rules(),
        (new UploadBrandFontRequest())->messages()
    );

    expect($validator->fails())->toBeFalse();
});

it('prohibits typography font file fields in professional site settings updates', function () {
    $validator = Validator::make([
        'settings' => [
            'design' => [
                'typography' => [
                    'font_file_url' => 'https://cdn.example.com/fonts/brand.woff2',
                    'font_file_path' => 'fonts/brand.woff2',
                    'font_file_name' => 'brand.woff2',
                ],
            ],
        ],
    ], (new UpdateSiteRequest())->rules(), (new UpdateSiteRequest())->messages());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('settings.design.typography.font_file_url'))->toBeTrue();
    expect($validator->errors()->has('settings.design.typography.font_file_path'))->toBeTrue();
    expect($validator->errors()->has('settings.design.typography.font_file_name'))->toBeTrue();
});

it('prohibits typography font file fields in staff site settings updates', function () {
    $validator = Validator::make([
        'settings' => [
            'design' => [
                'typography' => [
                    'font_file_url' => 'https://cdn.example.com/fonts/brand.woff2',
                    'font_file_path' => 'fonts/brand.woff2',
                    'font_file_name' => 'brand.woff2',
                ],
            ],
        ],
    ], (new StaffUpdateSiteRequest())->rules(), (new StaffUpdateSiteRequest())->messages());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('settings.design.typography.font_file_url'))->toBeTrue();
    expect($validator->errors()->has('settings.design.typography.font_file_path'))->toBeTrue();
    expect($validator->errors()->has('settings.design.typography.font_file_name'))->toBeTrue();
});

function setupBrandFontSchema(): void
{
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand_fonts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NOT NULL,
        slot TEXT NOT NULL,
        file_name TEXT NULL,
        file_path TEXT NOT NULL,
        file_url TEXT NOT NULL,
        format TEXT NOT NULL,
        file_hash TEXT NOT NULL,
        size_bytes INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');
}
