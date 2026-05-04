<?php

use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// #4-04: tokens on ProfessionalIntegration are stored encrypted at rest.

beforeEach(function () {
    attachTestSchemas();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        provider TEXT NULL,
        access_token TEXT NULL,
        refresh_token TEXT NULL,
        storefront_token TEXT NULL,
        external_account_id TEXT NULL,
        expires_at TEXT NULL,
        catalog_latest_time TEXT NULL,
        last_catalog_sync_at TEXT NULL,
        last_catalog_sync_error TEXT NULL,
        provider_metadata TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

it('storefront_token is stored as ciphertext, not plaintext', function () {
    $plaintext = 'shpat_plaintext_storefront_secret_'.Str::random(16);

    $integration = ProfessionalIntegration::create([
        'professional_id' => (string) Str::uuid(),
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'storefront_token' => $plaintext,
    ]);

    $raw = DB::connection('pgsql')
        ->table('core.professional_integrations')
        ->where('id', $integration->id)
        ->value('storefront_token');

    expect($raw)->not->toBe($plaintext);
    expect($raw)->not->toContain('shpat_plaintext');
    expect($integration->storefront_token)->toBe($plaintext);
});

it('access_token is stored as ciphertext, not plaintext', function () {
    $plaintext = 'shpat_access_token_'.Str::random(16);

    $integration = ProfessionalIntegration::create([
        'professional_id' => (string) Str::uuid(),
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'access_token' => $plaintext,
    ]);

    $raw = DB::connection('pgsql')
        ->table('core.professional_integrations')
        ->where('id', $integration->id)
        ->value('access_token');

    expect($raw)->not->toBe($plaintext);
    expect($integration->access_token)->toBe($plaintext);
});

it('refresh_token is stored as ciphertext, not plaintext', function () {
    $plaintext = 'shprt_refresh_token_'.Str::random(16);

    $integration = ProfessionalIntegration::create([
        'professional_id' => (string) Str::uuid(),
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'refresh_token' => $plaintext,
    ]);

    $raw = DB::connection('pgsql')
        ->table('core.professional_integrations')
        ->where('id', $integration->id)
        ->value('refresh_token');

    expect($raw)->not->toBe($plaintext);
    expect($integration->refresh_token)->toBe($plaintext);
});
