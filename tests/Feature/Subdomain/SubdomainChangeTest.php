<?php

use App\Actions\Site\UpdateSiteAction;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    setupCoreSchema();

    DB::table('core.site_subdomain_aliases')->delete();
    DB::table('core.public_site_payload')->delete();
    DB::table('core.sites')->delete();
    DB::table('core.professionals')->delete();
});

it('prevents professionals from changing subdomain within 30 days', function () {
    Carbon::setTestNow('2025-01-15');

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::table('core.professionals')->insert([
        'id' => $proId,
        'display_name' => 'Test Pro',
    ]);

    DB::table('core.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => 'old',
        'subdomain_changed_at' => Carbon::now()->subDays(10)->toDateTimeString(),
    ]);

    $professional = Professional::findOrFail($proId);

    $action = app(UpdateSiteAction::class);

    $this->expectException(ValidationException::class);
    $action->execute($professional, ['subdomain' => 'new']);
});

it('stores old subdomain as alias after a valid change', function () {
    Carbon::setTestNow('2025-01-15');

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::table('core.professionals')->insert([
        'id' => $proId,
        'display_name' => 'Test Pro',
    ]);

    DB::table('core.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => 'old',
        'subdomain_changed_at' => Carbon::now()->subDays(31)->toDateTimeString(),
    ]);

    $professional = Professional::findOrFail($proId);
    $action = app(UpdateSiteAction::class);

    $action->execute($professional, ['subdomain' => 'new']);

    $site = Site::findOrFail($siteId);
    expect($site->subdomain)->toBe('new');
    expect($site->subdomain_changed_at->toDateString())->toBe('2025-01-15');

    $alias = DB::table('core.site_subdomain_aliases')
        ->where('site_id', $siteId)
        ->first();

    expect($alias)->not->toBeNull();
    expect($alias->subdomain)->toBe('old');
});

it('redirects old subdomain to new site host', function () {
    $domain = config('comet.public_domain');
    $oldHost = 'old.' . $domain;
    $newHost = 'new.' . $domain;

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $aliasId = (string) Str::uuid();

    DB::table('core.professionals')->insert([
        'id' => $proId,
        'display_name' => 'Test Pro',
    ]);

    DB::table('core.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => 'new',
        'is_published' => 1,
    ]);

    DB::table('core.site_subdomain_aliases')->insert([
        'id' => $aliasId,
        'site_id' => $siteId,
        'subdomain' => 'old',
        'created_at' => now()->toDateTimeString(),
    ]);

    DB::table('core.public_site_payload')->insert([
        'site_id' => $siteId,
        'subdomain' => 'new',
        'payload' => json_encode(['site' => ['id' => $siteId]]),
    ]);

    $response = $this->get('http://' . $oldHost . '/api/public/site');

    $response->assertStatus(301);
    $response->assertRedirect('http://' . $newHost . '/api/public/site');
});

function setupCoreSchema(): void
{
    $driver = DB::getDriverName();

    if ($driver === 'sqlite') {
        try {
            DB::statement("ATTACH DATABASE ':memory:' AS core");
        } catch (\Throwable $e) {
            // Ignore if already attached.
        }

        DB::statement('CREATE TABLE IF NOT EXISTS core.professionals (
            id TEXT PRIMARY KEY,
            display_name TEXT NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS core.sites (
            id TEXT PRIMARY KEY,
            professional_id TEXT NULL,
            subdomain TEXT NULL,
            subdomain_changed_at TEXT NULL,
            is_published INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS core.site_subdomain_aliases (
            id TEXT PRIMARY KEY,
            site_id TEXT NOT NULL,
            subdomain TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS core.public_site_payload (
            site_id TEXT PRIMARY KEY,
            subdomain TEXT NULL,
            payload TEXT NULL
        )');

        return;
    }

    DB::statement('CREATE SCHEMA IF NOT EXISTS core');

    DB::statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id uuid PRIMARY KEY,
        display_name varchar(255) NULL
    )');

    DB::statement('CREATE TABLE IF NOT EXISTS core.sites (
        id uuid PRIMARY KEY,
        professional_id uuid NULL,
        subdomain varchar(63) NULL,
        subdomain_changed_at timestamptz NULL,
        is_published boolean NULL,
        created_at timestamptz NULL,
        updated_at timestamptz NULL
    )');

    DB::statement('CREATE TABLE IF NOT EXISTS core.site_subdomain_aliases (
        id uuid PRIMARY KEY,
        site_id uuid NOT NULL,
        subdomain varchar(63) NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now()
    )');

    DB::statement('CREATE TABLE IF NOT EXISTS core.public_site_payload (
        site_id uuid PRIMARY KEY,
        subdomain varchar(63) NULL,
        payload jsonb NULL
    )');
}
