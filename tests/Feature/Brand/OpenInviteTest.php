<?php

use App\Http\Controllers\Api\Professional\OpenInviteController;
use App\Http\Controllers\Api\PublicSite\PublicOpenInviteController;
use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\AccountTypeDefaultsService;
use App\Services\Professional\BrandAffiliateInviteService;
use App\Services\Professional\BrandPartnerLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    // TestCase::setUp already redirects 'pgsql' to in-memory SQLite and sets
    // it as the default connection, so we don't need to redefine it here.
    $conn = DB::connection('pgsql');

    foreach (['core', 'site', 'brand', 'notifications'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT,
        handle TEXT,
        handle_lc TEXT,
        display_name TEXT,
        bio TEXT,
        first_name TEXT,
        last_name TEXT,
        phone TEXT,
        primary_email TEXT,
        public_contact_number TEXT,
        public_contact_email TEXT,
        professional_type TEXT DEFAULT "professional",
        status TEXT DEFAULT "active",
        onboarding_step INTEGER DEFAULT 0,
        qr_slug TEXT,
        country_code TEXT,
        timezone TEXT,
        location_street_address TEXT,
        location_city TEXT,
        location_state TEXT,
        location_postcode TEXT,
        location_country TEXT,
        stripe_connect_account_id TEXT,
        stripe_customer_id TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
        id TEXT PRIMARY KEY,
        professional_id TEXT UNIQUE,
        abn TEXT,
        acn TEXT,
        legal_business_name TEXT,
        business_type TEXT,
        industries TEXT,
        estimated_annual_income TEXT,
        business_website TEXT,
        affiliate_visibility TEXT,
        brand_status TEXT,
        setup_complete INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        subdomain TEXT,
        theme_id TEXT,
        is_published INTEGER DEFAULT 0,
        settings TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT NOT NULL,
        brand_professional_id TEXT NOT NULL,
        slot INTEGER NOT NULL DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_affiliate_invites (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NOT NULL,
        token TEXT NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT "pending",
        invite_type TEXT NOT NULL DEFAULT "generic",
        email TEXT,
        email_lc TEXT,
        phone TEXT,
        first_name TEXT,
        last_name TEXT,
        message TEXT,
        claimed_professional_id TEXT,
        accepted_at TEXT,
        expires_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    // ProfessionalIntegration is queried by ProfessionalSetupService and
    // related onboarding flows when an affiliate connects to a brand. Without
    // it, the connection-creating tests fail with "no such table".
    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        access_token TEXT NULL,
        provider_metadata TEXT NULL,
        status TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');
})->group('open-invite');

function createBrand(string $handle = 'testbrand', string $brandStatus = 'active'): Professional
{
    $brandId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $brandId,
        'auth_user_id' => 'auth-' . Str::random(8),
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => "{$handle}@example.com",
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('brand.brand_profiles')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'brand_status' => $brandStatus,
        'setup_complete' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Create site for brand
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'subdomain' => $handle,
        'settings' => json_encode(['design' => ['media' => ['brand_logo_url' => 'https://example.com/logo.png'], 'dark_color' => '#000000']]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::find($brandId);
}

function createAffiliate(string $handle = 'testaffiliate'): Professional
{
    $affiliateId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $affiliateId,
        'auth_user_id' => 'auth-' . Str::random(8),
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => "{$handle}@example.com",
        'professional_type' => 'professional',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Create site for affiliate
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $affiliateId,
        'subdomain' => $handle,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::find($affiliateId);
}

// --- Public Preview Tests ---

it('returns brand preview for valid active brand', function () {
    $brand = createBrand('acmebrand');

    $controller = new PublicOpenInviteController;
    $response = $controller->show('acmebrand');

    expect($response->status())->toBe(200);

    $data = $response->getData(true);
    expect($data['brand']['handle'])->toBe('acmebrand');
    expect($data['brand']['display_name'])->toBe('Acmebrand');
    expect($data['brand']['professional_id'])->toBe($brand->id);
    expect($data['brand']['brand_logo_url'])->toBe('https://example.com/logo.png');
    expect($data['brand']['brand_color'])->toBe('#000000');
});

it('returns 404 for nonexistent handle', function () {
    $controller = new PublicOpenInviteController;
    $response = $controller->show('nonexistent');

    expect($response->status())->toBe(404);
});

it('returns 404 for deactivated brand', function () {
    createBrand('deadbrand', 'deactivated');

    $controller = new PublicOpenInviteController;
    $response = $controller->show('deadbrand');

    expect($response->status())->toBe(404);
});

it('returns 404 for non-brand professional', function () {
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => 'auth-' . Str::random(8),
        'handle' => 'regularpro',
        'handle_lc' => 'regularpro',
        'display_name' => 'Regular Pro',
        'primary_email' => 'regularpro@example.com',
        'professional_type' => 'professional',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $controller = new PublicOpenInviteController;
    $response = $controller->show('regularpro');

    expect($response->status())->toBe(404);
});

it('normalises handle to lowercase for lookup', function () {
    createBrand('mixedcase');

    $controller = new PublicOpenInviteController;
    $response = $controller->show('MixedCase');

    expect($response->status())->toBe(200);
});

// --- Authenticated Claim Tests ---

it('creates connection when authenticated affiliate claims open invite', function () {
    $brand = createBrand('claimablebrand');
    $affiliate = createAffiliate('claimingaffiliate');

    $inviteService = new BrandAffiliateInviteService(new BrandPartnerLinkService);
    $brandPartnerLinks = new BrandPartnerLinkService;
    $accountDefaults = Mockery::mock(AccountTypeDefaultsService::class);
    $accountDefaults->shouldReceive('applyAffiliateDefaults');

    $cacheService = Mockery::mock(ProfessionalCacheService::class);
    $cacheService->shouldReceive('invalidateProfessional');
    app()->instance(ProfessionalCacheService::class, $cacheService);

    $request = Request::create('/api/join/claimablebrand', 'POST');
    $request->attributes->set('professional', $affiliate);

    $controller = new OpenInviteController;
    $response = $controller->claim($request, 'claimablebrand', $inviteService, $brandPartnerLinks, $accountDefaults);

    expect($response->status())->toBe(200);

    $data = $response->getData(true);
    expect($data['invite']['status'])->toBe('accepted');
    expect($data['invite']['invite_type'])->toBe('generic');
    expect($data['invite']['brand_professional_id'])->toBe($brand->id);
    expect($data['invite']['claimed_professional_id'])->toBe($affiliate->id);

    // Verify BrandPartnerLink was created
    $link = BrandPartnerLink::where('affiliate_professional_id', $affiliate->id)
        ->where('brand_professional_id', $brand->id)
        ->first();
    expect($link)->not->toBeNull();
    expect($link->slot)->toBe(0);

    // Verify audit invite record was created
    $invite = BrandAffiliateInvite::where('brand_professional_id', $brand->id)
        ->where('claimed_professional_id', $affiliate->id)
        ->first();
    expect($invite)->not->toBeNull();
    expect($invite->invite_type)->toBe('generic');
    expect($invite->status)->toBe('accepted');
    expect($invite->email)->toBeNull();
});

it('returns 404 when brand handle does not exist', function () {
    $affiliate = createAffiliate('lonelyaffiliate');

    $inviteService = Mockery::mock(BrandAffiliateInviteService::class);
    $brandPartnerLinks = Mockery::mock(BrandPartnerLinkService::class);
    $accountDefaults = Mockery::mock(AccountTypeDefaultsService::class);

    $request = Request::create('/api/join/ghost', 'POST');
    $request->attributes->set('professional', $affiliate);

    $controller = new OpenInviteController;
    $response = $controller->claim($request, 'ghost', $inviteService, $brandPartnerLinks, $accountDefaults);

    expect($response->status())->toBe(404);
});

it('returns 422 when brand account tries to claim', function () {
    $brand = createBrand('targetbrand');
    $claimingBrand = createBrand('claimingbrand');

    $inviteService = new BrandAffiliateInviteService(new BrandPartnerLinkService);
    $brandPartnerLinks = new BrandPartnerLinkService;
    $accountDefaults = Mockery::mock(AccountTypeDefaultsService::class);

    $request = Request::create('/api/join/targetbrand', 'POST');
    $request->attributes->set('professional', $claimingBrand);

    $controller = new OpenInviteController;
    $response = $controller->claim($request, 'targetbrand', $inviteService, $brandPartnerLinks, $accountDefaults);

    expect($response->status())->toBe(422);
    expect($response->getData(true)['message'])->toContain('Brand accounts cannot');
});

it('returns 422 when affiliate is already connected', function () {
    $brand = createBrand('connectedbrand');
    $affiliate = createAffiliate('connectedaffiliate');

    // Pre-create the connection
    DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $affiliate->id,
        'brand_professional_id' => $brand->id,
        'slot' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $inviteService = new BrandAffiliateInviteService(new BrandPartnerLinkService);
    $brandPartnerLinks = new BrandPartnerLinkService;
    $accountDefaults = Mockery::mock(AccountTypeDefaultsService::class);

    $request = Request::create('/api/join/connectedbrand', 'POST');
    $request->attributes->set('professional', $affiliate);

    $controller = new OpenInviteController;
    $response = $controller->claim($request, 'connectedbrand', $inviteService, $brandPartnerLinks, $accountDefaults);

    expect($response->status())->toBe(422);
    expect($response->getData(true)['message'])->toContain('already connected');
});

it('returns 422 when brand is deactivated', function () {
    createBrand('deactivatedbrand', 'deactivated');
    $affiliate = createAffiliate('eageraffiliate');

    $inviteService = new BrandAffiliateInviteService(new BrandPartnerLinkService);
    $brandPartnerLinks = new BrandPartnerLinkService;
    $accountDefaults = Mockery::mock(AccountTypeDefaultsService::class);

    $request = Request::create('/api/join/deactivatedbrand', 'POST');
    $request->attributes->set('professional', $affiliate);

    $controller = new OpenInviteController;
    $response = $controller->claim($request, 'deactivatedbrand', $inviteService, $brandPartnerLinks, $accountDefaults);

    expect($response->status())->toBe(422);
    expect($response->getData(true)['message'])->toContain('not currently accepting');
});

// --- Service Unit Tests ---

it('claimOpenInvite creates invite and partner link in one transaction', function () {
    $brand = createBrand('servicebrand');
    $affiliate = createAffiliate('serviceaffiliate');

    $service = new BrandAffiliateInviteService(new BrandPartnerLinkService);
    $invite = $service->claimOpenInvite($brand, $affiliate);

    expect($invite->status)->toBe('accepted');
    expect($invite->invite_type)->toBe('generic');
    expect($invite->email)->toBeNull();
    expect($invite->brand_professional_id)->toBe($brand->id);
    expect($invite->claimed_professional_id)->toBe($affiliate->id);
    expect($invite->accepted_at)->not->toBeNull();
    expect($invite->expires_at)->toBeNull();

    // Token should be generated
    expect($invite->token)->toBeString();
    expect(strlen($invite->token))->toBe(48);

    // Partner link should exist
    expect(BrandPartnerLink::where('affiliate_professional_id', $affiliate->id)
        ->where('brand_professional_id', $brand->id)
        ->exists())->toBeTrue();
});

it('claimOpenInvite throws when affiliate has no site', function () {
    $brand = createBrand('sitelessbrand');

    // Create affiliate without a site
    $affiliateId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $affiliateId,
        'auth_user_id' => 'auth-' . Str::random(8),
        'handle' => 'nositeaffiliate',
        'handle_lc' => 'nositeaffiliate',
        'display_name' => 'No Site',
        'primary_email' => 'nosite@example.com',
        'professional_type' => 'professional',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $affiliate = Professional::find($affiliateId);

    $service = new BrandAffiliateInviteService(new BrandPartnerLinkService);

    expect(fn () => $service->claimOpenInvite($brand, $affiliate))
        ->toThrow(RuntimeException::class, 'Your site could not be found');
});
