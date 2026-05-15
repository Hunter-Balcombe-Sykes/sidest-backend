<?php

use App\Http\Controllers\Api\Internal\EmbeddedSetupController;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    $this->controller = app(EmbeddedSetupController::class);
    $this->professionalId = (string) Str::uuid();
});

it('serves the overview payload from cache without hitting the database', function () {
    $cached = [
        'affiliate_count' => 7,
        'total_commission_cents' => 99900,
        'currency_code' => 'AUD',
        'commission_30d_cents' => 5000,
        'revenue_30d_cents' => 150000,
        'recent_sales' => [],
    ];

    Cache::put(
        CacheKeyGenerator::embeddedSetupOverview($this->professionalId),
        $cached,
        60,
    );

    // Spy proxies real DB calls, so an accidental cold-miss surfaces as a
    // real missing-table failure (loud), not a Mockery expectation violation
    // (silently misleading). We then assert table() was never invoked.
    DB::spy();

    $request = Request::create('/internal/embedded/overview', 'GET');
    $request->attributes->set('embedded_professional_id', $this->professionalId);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true);

    expect($data)->toBe($cached);

    DB::shouldNotHaveReceived('table');
});
