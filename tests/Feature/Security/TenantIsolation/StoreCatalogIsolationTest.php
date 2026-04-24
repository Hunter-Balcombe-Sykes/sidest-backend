<?php

use App\Http\Controllers\Api\Professional\Store\BrandCatalogController;
use App\Http\Requests\Api\Professional\Store\ToggleProductActiveRequest;
use App\Services\Store\BrandCatalogService;

beforeEach(function () {
    tenantHelpersEnsureTables();
});

it('toggleActive uses the authenticated brands own catalog service integration', function () {
    [$brandA, $brandB] = createTwoTenants('brand');

    // Capture which professional the catalog service is called with.
    $calledWith = null;
    $this->mock(BrandCatalogService::class, function ($mock) use (&$calledWith) {
        $mock->shouldReceive('resolveBrandIntegration')
            ->once()
            ->andReturnUsing(function ($pro) use (&$calledWith) {
                $calledWith = $pro->id;
                throw new \RuntimeException('Your Shopify store is not connected.', 422);
            });
    });

    $plainReq = tenantRequestAs($brandB, ['active' => true], 'PATCH');
    $req = ToggleProductActiveRequest::createFrom($plainReq);
    $req->setContainer(app());
    $req->attributes->set('professional', $brandB);
    $req->validateResolved();

    $response = app(BrandCatalogController::class)->toggleActive($req, 'gid://shopify/Product/99');

    // The service was invoked on behalf of Brand B, not Brand A.
    expect($calledWith)->toBe($brandB->id);
    expect($calledWith)->not->toBe($brandA->id);

    // Controller returned an error (no Shopify), not a success for Brand A's data.
    expect($response->getStatusCode())->toBe(422);
});
