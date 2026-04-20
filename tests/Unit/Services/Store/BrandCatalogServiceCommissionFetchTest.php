<?php

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Store\BrandCatalogService;
use Illuminate\Support\Facades\Http;

use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('fetches commission_override metafields for an array of product GIDs in one call', function () {
    Http::fake([
        '*/admin/api/*/graphql.json' => Http::response([
            'data' => [
                'nodes' => [
                    [
                        'id' => 'gid://shopify/Product/1',
                        'metafield' => ['value' => '25.5'],
                    ],
                    [
                        'id' => 'gid://shopify/Product/2',
                        'metafield' => null,
                    ],
                ],
            ],
        ], 200),
    ]);

    $integration = new ProfessionalIntegration([
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'access_token' => 'shpat_test',
        'provider_metadata' => ['shop_domain' => 'test-shop.myshopify.com'],
    ]);
    $integration->id = 'int-123';

    $service = app(BrandCatalogService::class);
    $overrides = $service->fetchCommissionOverridesForProducts($integration, [
        'gid://shopify/Product/1',
        'gid://shopify/Product/2',
    ]);

    expect($overrides)->toBe([
        'gid://shopify/Product/1' => 25.5,
        'gid://shopify/Product/2' => null,
    ]);

    Http::assertSentCount(1);
});

it('returns an empty array when given no product GIDs', function () {
    Http::fake();

    $integration = new ProfessionalIntegration([
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'access_token' => 'shpat_test',
        'provider_metadata' => ['shop_domain' => 'test-shop.myshopify.com'],
    ]);
    $integration->id = 'int-123';

    $service = app(BrandCatalogService::class);
    expect($service->fetchCommissionOverridesForProducts($integration, []))->toBe([]);

    Http::assertNothingSent();
});
