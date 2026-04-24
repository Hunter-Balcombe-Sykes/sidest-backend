<?php

use App\Models\Core\Gdpr\GdprRequest;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement('ATTACH DATABASE \':memory:\' AS core');
    } catch (\Throwable) {
    }

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

    // SQLite: schema prefix belongs on index name, not table name
    $conn->statement('CREATE UNIQUE INDEX IF NOT EXISTS core.gdpr_requests_payload_hash_unique ON gdpr_requests (payload_hash)');
});

it('persists a new GDPR request with the expected cast shape', function () {
    $request = GdprRequest::create([
        'topic' => GdprRequest::TOPIC_SHOP_REDACT,
        'shop_domain' => 'test-brand.myshopify.com',
        'shopify_shop_id' => 12345,
        'payload_hash' => str_repeat('a', 64),
        'payload' => ['shop_id' => 12345, 'shop_domain' => 'test-brand.myshopify.com'],
        'status' => GdprRequest::STATUS_RECEIVED,
        'received_at' => now(),
    ]);

    $fresh = GdprRequest::find($request->id);

    expect($fresh->topic)->toBe('shop/redact');
    expect($fresh->payload)->toBeArray();
    expect($fresh->payload['shop_id'])->toBe(12345);
    expect($fresh->status)->toBe('received');
});

it('rejects duplicate payload_hash via the unique index', function () {
    $hash = str_repeat('b', 64);

    GdprRequest::create([
        'topic' => GdprRequest::TOPIC_CUSTOMERS_REDACT,
        'shop_domain' => 'test-brand.myshopify.com',
        'payload_hash' => $hash,
        'payload' => ['customer' => ['email' => 'x@example.com']],
        'received_at' => now(),
    ]);

    expect(fn () => GdprRequest::create([
        'topic' => GdprRequest::TOPIC_CUSTOMERS_REDACT,
        'shop_domain' => 'test-brand.myshopify.com',
        'payload_hash' => $hash,
        'payload' => ['customer' => ['email' => 'x@example.com']],
        'received_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('exposes topic and status constants', function () {
    expect(GdprRequest::TOPIC_CUSTOMERS_DATA_REQUEST)->toBe('customers/data_request');
    expect(GdprRequest::TOPIC_CUSTOMERS_REDACT)->toBe('customers/redact');
    expect(GdprRequest::TOPIC_SHOP_REDACT)->toBe('shop/redact');

    expect(GdprRequest::STATUS_RECEIVED)->toBe('received');
    expect(GdprRequest::STATUS_PROCESSING)->toBe('processing');
    expect(GdprRequest::STATUS_COMPLETED)->toBe('completed');
    expect(GdprRequest::STATUS_FAILED)->toBe('failed');
    expect(GdprRequest::STATUS_SKIPPED)->toBe('skipped');
});
