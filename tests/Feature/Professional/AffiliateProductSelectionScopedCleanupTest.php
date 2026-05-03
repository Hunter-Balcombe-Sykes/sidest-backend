<?php

use App\Http\Controllers\Api\Professional\Store\AffiliateProductController;
use App\Models\Core\Professional\Professional;
use App\Services\Store\AffiliateProductCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('rejects store when affiliate is not linked to the specified brand', function () {
    $affiliate = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'professional']);
    $unlinkedBrandId = (string) Str::uuid();

    // Mock the linkage check to return false
    $linkQuery = Mockery::mock();
    $linkQuery->shouldReceive('where')->andReturnSelf();
    $linkQuery->shouldReceive('exists')->andReturn(false);
    DB::shouldReceive('table')->with('brand.brand_partner_links')->andReturn($linkQuery);

    $request = Request::create('/', 'POST', [
        'brand_professional_id' => $unlinkedBrandId,
        'shopify_product_gid' => 'gid://shopify/Product/123',
        'sort_order' => 0,
    ]);
    $request->attributes->set('professional', $affiliate);

    $catalog = Mockery::mock(AffiliateProductCatalogService::class);
    $controller = new AffiliateProductController($catalog);
    $response = $controller->store($request);

    expect($response->status())->toBe(422);
});
