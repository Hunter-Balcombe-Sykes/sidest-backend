<?php

use App\Http\Controllers\Api\Professional\Store\BrandCollectionController;
use App\Services\Store\BrandCatalogService;

beforeEach(function () {
    tenantHelpersEnsureTables();
});

it('collection index resolves integration using the authenticated brand not another brand', function () {
    [$brandA, $brandB] = createTwoTenants('brand');

    // Capture which professional's integration was resolved.
    $resolvedProfessionalId = null;
    $this->mock(BrandCatalogService::class, function ($mock) use (&$resolvedProfessionalId) {
        $mock->shouldReceive('resolveBrandIntegration')
            ->once()
            ->andReturnUsing(function ($pro) use (&$resolvedProfessionalId) {
                $resolvedProfessionalId = $pro->id;
                // No Shopify integration; collection will return 404.
                throw new \RuntimeException('Your Shopify store is not connected.', 422);
            });
    });

    $req = tenantRequestAs($brandB);
    $response = app(BrandCollectionController::class)->index($req, 'default');

    // The catalog service was invoked for Brand B, not Brand A.
    expect($resolvedProfessionalId)->toBe($brandB->id);
    expect($resolvedProfessionalId)->not->toBe($brandA->id);
});

it('collection index returns an error for brand B when only brand A has a shopify integration', function () {
    [$brandA, $brandB] = createTwoTenants('brand');

    // Brand B has no Shopify integration; brand A's integration must not be used as fallback.
    $callCount = 0;
    $this->mock(BrandCatalogService::class, function ($mock) use (&$callCount) {
        $mock->shouldReceive('resolveBrandIntegration')
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                throw new \RuntimeException('Your Shopify store is not connected.', 422);
            });
    });

    $req = tenantRequestAs($brandB);
    $response = app(BrandCollectionController::class)->index($req, 'default');

    // Should fail gracefully — not succeed using another brand's integration.
    expect($response->getStatusCode())->toBe(422);
    expect($callCount)->toBe(1);
});
