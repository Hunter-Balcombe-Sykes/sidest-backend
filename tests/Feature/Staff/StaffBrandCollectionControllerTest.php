<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffBrandCollectionController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Store\BrandCatalogService;
use Illuminate\Support\Str;

function makeStaffCollectionProfessional(): Professional
{
    $pro = new Professional;
    $pro->id = (string) Str::uuid();
    $pro->professional_type = 'brand';

    return $pro;
}

it('returns 422 for an unknown collection type', function () {
    $pro = makeStaffCollectionProfessional();
    $catalogService = Mockery::mock(BrandCatalogService::class);

    $controller = new StaffBrandCollectionController($catalogService);
    $response = $controller->index($pro, 'rubbish');

    expect($response->getStatusCode())->toBe(422);
});

it('returns empty products when the brand has no collection handle stored yet', function () {
    $pro = makeStaffCollectionProfessional();
    $integration = new ProfessionalIntegration(['provider' => 'shopify']);
    $integration->id = (string) Str::uuid();

    $catalogService = Mockery::mock(BrandCatalogService::class);
    $catalogService->shouldReceive('resolveBrandIntegration')
        ->once()
        ->andReturn(['integration' => $integration, 'metadata' => []]);

    $controller = new StaffBrandCollectionController($catalogService);
    $response = $controller->index($pro, 'active');
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['products'])->toBe([]);
});

it('returns Shopify products when the handle resolves to a collection GID', function () {
    $pro = makeStaffCollectionProfessional();
    $integration = new ProfessionalIntegration(['provider' => 'shopify']);
    $integration->id = (string) Str::uuid();

    $catalogService = Mockery::mock(BrandCatalogService::class);
    $catalogService->shouldReceive('resolveBrandIntegration')
        ->andReturn(['integration' => $integration, 'metadata' => ['default_collection_handle' => 'frontpage']]);
    $catalogService->shouldReceive('resolveCollectionGid')
        ->with($integration, 'frontpage')
        ->andReturn('gid://shopify/Collection/1');
    $catalogService->shouldReceive('fetchCollectionProducts')
        ->with($integration, 'gid://shopify/Collection/1')
        ->andReturn([
            ['gid' => 'gid://shopify/Product/1', 'title' => 'Tee'],
        ]);

    $controller = new StaffBrandCollectionController($catalogService);
    $response = $controller->index($pro, 'default');
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['products'])->toHaveCount(1);
});

it('returns 502 when Shopify throws an unexpected error', function () {
    $pro = makeStaffCollectionProfessional();
    $integration = new ProfessionalIntegration(['provider' => 'shopify']);
    $integration->id = (string) Str::uuid();

    $catalogService = Mockery::mock(BrandCatalogService::class);
    $catalogService->shouldReceive('resolveBrandIntegration')
        ->andReturn(['integration' => $integration, 'metadata' => ['favourites_collection_handle' => 'faves']]);
    $catalogService->shouldReceive('resolveCollectionGid')
        ->andReturn('gid://shopify/Collection/9');
    $catalogService->shouldReceive('fetchCollectionProducts')
        ->andThrow(new \Exception('Boom'));

    $controller = new StaffBrandCollectionController($catalogService);
    $response = $controller->index($pro, 'favourites');

    expect($response->getStatusCode())->toBe(502);
});
