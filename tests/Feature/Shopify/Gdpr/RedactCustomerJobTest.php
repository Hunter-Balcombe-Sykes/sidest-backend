<?php

use App\Jobs\Shopify\Gdpr\RedactCustomerJob;
use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement('ATTACH DATABASE \':memory:\' AS core');
        $conn->statement('ATTACH DATABASE \':memory:\' AS notifications');
        $conn->statement('ATTACH DATABASE \':memory:\' AS site');
        $conn->statement('ATTACH DATABASE \':memory:\' AS analytics');
        $conn->statement('ATTACH DATABASE \':memory:\' AS commerce');
    } catch (\Throwable) {
    }

    // Phase 1 schema — RedactCustomerJob scrubs PII on these.
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.orders (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        customer_id TEXT,
        shopify_data TEXT,
        updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.order_events (
        id TEXT PRIMARY KEY,
        order_id TEXT,
        metadata TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        shopify_shop_domain TEXT,
        provider_metadata TEXT,
        access_token TEXT,
        refresh_token TEXT,
        external_account_id TEXT,
        expires_at TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.customers (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        email TEXT,
        phone TEXT,
        full_name TEXT,
        source TEXT,
        notes TEXT,
        external_id TEXT,
        redacted_at TEXT,
        marketing_opt_in_cached INTEGER,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        list_key TEXT,
        email TEXT,
        email_lc TEXT,
        full_name TEXT,
        status TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        site_id TEXT,
        name TEXT,
        email TEXT,
        phone TEXT,
        subject TEXT,
        message TEXT,
        ip_hash TEXT,
        user_agent TEXT,
        read_at TEXT,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS analytics.booking_events (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        site_id TEXT,
        occurred_at TEXT,
        status TEXT,
        source TEXT,
        customer_name TEXT,
        customer_email TEXT,
        customer_phone TEXT,
        currency_code TEXT,
        amount_paid_cents INTEGER,
        raw_payload TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.gdpr_requests (
        id TEXT PRIMARY KEY,
        topic TEXT NOT NULL,
        shop_domain TEXT NOT NULL,
        shopify_shop_id INTEGER,
        payload_hash TEXT NOT NULL,
        payload TEXT NOT NULL,
        professional_id TEXT,
        status TEXT NOT NULL DEFAULT \'received\',
        error TEXT,
        received_at TEXT,
        completed_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

function seedCustomerRedactFixture(): array
{
    $professionalId = 'brand-cust-'.uniqid();
    $shopDomain = 'test-brand.myshopify.com';

    // shopify_shop_domain is a generated column — raw insert bypasses $fillable
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $professionalId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'provider_metadata' => json_encode(['shop_domain' => $shopDomain]),
        'shopify_shop_domain' => $shopDomain,
        'access_token' => 'shpat_live',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customer = Customer::create([
        'professional_id' => $professionalId,
        'email' => 'target@example.com',
        'phone' => '+1555',
        'full_name' => 'Target Customer',
        'source' => 'shopify',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('notifications.email_subscriptions')->insert([
        'id' => 'sub-1',
        'professional_id' => $professionalId,
        'list_key' => 'marketing',
        'email' => 'target@example.com',
        'email_lc' => 'target@example.com',
        'full_name' => 'Target Customer',
        'status' => 'subscribed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('site.enquiries')->insert([
        'id' => 'enq-1',
        'professional_id' => $professionalId,
        'site_id' => 'site-1',
        'name' => 'Target Customer',
        'email' => 'target@example.com',
        'phone' => '+1555',
        'subject' => 'Question',
        'message' => 'Hi there',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Denormalised PII in a booking — must be scrubbed by the job.
    DB::table('analytics.booking_events')->insert([
        'id' => 'bk-target',
        'professional_id' => $professionalId,
        'site_id' => 'site-1',
        'occurred_at' => now(),
        'status' => 'completed',
        'source' => 'site_booking_checkout',
        'customer_name' => 'Target Customer',
        'customer_email' => 'target@example.com',
        'customer_phone' => '+1555',
        'raw_payload' => json_encode(['customer' => ['email' => 'target@example.com']]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A second booking for a DIFFERENT customer — must not be touched.
    DB::table('analytics.booking_events')->insert([
        'id' => 'bk-other',
        'professional_id' => $professionalId,
        'site_id' => 'site-1',
        'occurred_at' => now(),
        'status' => 'completed',
        'source' => 'site_booking_checkout',
        'customer_name' => 'Other Customer',
        'customer_email' => 'other@example.com',
        'customer_phone' => '+9999',
        'raw_payload' => json_encode(['customer' => ['email' => 'other@example.com']]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $gdpr = GdprRequest::create([
        'topic' => GdprRequest::TOPIC_CUSTOMERS_REDACT,
        'shop_domain' => $shopDomain,
        'payload_hash' => str_repeat('d', 64),
        'payload' => [
            'shop_domain' => $shopDomain,
            'customer' => ['id' => 99, 'email' => 'target@example.com', 'phone' => '+1555'],
            'orders_to_redact' => [],
        ],
        'received_at' => now(),
    ]);

    return compact('professionalId', 'customer', 'gdpr');
}

it('anonymises the matching customer row and sets redacted_at', function () {
    $ctx = seedCustomerRedactFixture();

    (new RedactCustomerJob($ctx['gdpr']->id))->handle();

    $customer = Customer::find($ctx['customer']->id);
    expect($customer->email)->toStartWith('redacted-');
    expect($customer->full_name)->toBe('Redacted Customer');
    expect($customer->phone)->toBeNull();
    expect($customer->redacted_at)->not->toBeNull();
});

it('hard-deletes the matching email_subscriptions row', function () {
    seedCustomerRedactFixture();

    (new RedactCustomerJob(GdprRequest::first()->id))->handle();

    $subCount = DB::table('notifications.email_subscriptions')
        ->where('email_lc', 'target@example.com')
        ->count();
    expect($subCount)->toBe(0);
});

it('hard-deletes the matching site.enquiries row', function () {
    seedCustomerRedactFixture();

    (new RedactCustomerJob(GdprRequest::first()->id))->handle();

    $enqCount = DB::table('site.enquiries')
        ->where('email', 'target@example.com')
        ->count();
    expect($enqCount)->toBe(0);
});

it('scrubs denormalised PII from analytics.booking_events for the matching email', function () {
    seedCustomerRedactFixture();

    (new RedactCustomerJob(GdprRequest::first()->id))->handle();

    $target = DB::table('analytics.booking_events')->where('id', 'bk-target')->first();
    expect($target->customer_email)->toBeNull();
    expect($target->customer_name)->toBeNull();
    expect($target->customer_phone)->toBeNull();
    expect($target->raw_payload)->toBe('{}');
});

it('leaves booking_events for other customers untouched', function () {
    seedCustomerRedactFixture();

    (new RedactCustomerJob(GdprRequest::first()->id))->handle();

    $other = DB::table('analytics.booking_events')->where('id', 'bk-other')->first();
    expect($other->customer_email)->toBe('other@example.com');
    expect($other->customer_name)->toBe('Other Customer');
    expect($other->customer_phone)->toBe('+9999');
});

it('marks the request completed', function () {
    $ctx = seedCustomerRedactFixture();

    (new RedactCustomerJob($ctx['gdpr']->id))->handle();

    expect(GdprRequest::find($ctx['gdpr']->id)->status)->toBe(GdprRequest::STATUS_COMPLETED);
});

it('cleans sibling data and marks completed even when no core.customers row exists', function () {
    $ctx = seedCustomerRedactFixture();

    // Force-delete (not soft-delete) — simulates a visitor-only contact with no booking record.
    $ctx['customer']->forceDelete();

    (new RedactCustomerJob($ctx['gdpr']->id))->handle();

    expect(GdprRequest::find($ctx['gdpr']->id)->status)->toBe(GdprRequest::STATUS_COMPLETED);

    $subCount = DB::table('notifications.email_subscriptions')
        ->where('email_lc', 'target@example.com')->count();
    expect($subCount)->toBe(0);

    $enqCount = DB::table('site.enquiries')
        ->where('email', 'target@example.com')->count();
    expect($enqCount)->toBe(0);
});

it('marks the request skipped when no data exists for the email in this shop', function () {
    $professionalId = 'ghost-brand';
    $shopDomain = 'ghost.myshopify.com';

    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $professionalId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'provider_metadata' => json_encode(['shop_domain' => $shopDomain]),
        'shopify_shop_domain' => $shopDomain,
        'access_token' => 'shpat_ghost',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $gdpr = GdprRequest::create([
        'topic' => GdprRequest::TOPIC_CUSTOMERS_REDACT,
        'shop_domain' => $shopDomain,
        'payload_hash' => str_repeat('z', 64),
        'payload' => [
            'customer' => ['id' => 0, 'email' => 'nobody@example.com'],
            'orders_to_redact' => [],
        ],
        'received_at' => now(),
    ]);

    (new RedactCustomerJob($gdpr->id))->handle();

    expect(GdprRequest::find($gdpr->id)->status)->toBe(GdprRequest::STATUS_SKIPPED);
});
