<?php

use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalServiceController;
use App\Models\Core\Professional\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    tenantHelpersEnsureTables();

    // square_variation_id is included because the controller adds whereNull('square_variation_id')
    // when the 'square' query param is absent. title matches the production column name on the model.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.services (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        title TEXT,
        square_variation_id TEXT,
        duration_minutes INTEGER,
        price_cents INTEGER,
        sort_order INTEGER,
        is_active INTEGER,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

it('service destroy refuses a service belonging to another professional', function () {
    [$a, $b] = createTwoTenants('affiliate');
    $now = now()->toDateTimeString();

    $serviceId = (string) Str::uuid();
    DB::table('site.services')->insert([
        'id' => $serviceId,
        'professional_id' => $a->id,
        'title' => 'Secret Cut',
        'price_cents' => 50_00,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $req = tenantRequestAs($b, [], 'DELETE');
    $service = Service::query()->findOrFail($serviceId);

    // ProfessionalServiceController::destroy() calls abort_unless($service->professional_id === $pro->id, 404)
    expect(fn () => app(ProfessionalServiceController::class)->destroy($req, $service))
        ->toThrow(HttpException::class);

    // Service must still exist.
    expect(DB::table('site.services')->where('id', $serviceId)->exists())->toBeTrue();
});

it('service index only returns services belonging to the authenticated professional', function () {
    [$a, $b] = createTwoTenants('affiliate');
    $now = now()->toDateTimeString();

    DB::table('site.services')->insert([
        ['id' => (string) Str::uuid(), 'professional_id' => $a->id, 'title' => 'A Service', 'price_cents' => 100_00, 'created_at' => $now, 'updated_at' => $now],
        ['id' => (string) Str::uuid(), 'professional_id' => $b->id, 'title' => 'B Service', 'price_cents' => 200_00, 'created_at' => $now, 'updated_at' => $now],
    ]);

    // flat=1 returns {services:[...]} and skips the ServiceCategory grouping query.
    $req = tenantRequestAs($b);
    $req->query->set('flat', '1');
    $response = app(ProfessionalServiceController::class)->index($req);
    $payload = $response->getData(true);

    $titles = collect($payload['services'] ?? [])->pluck('title')->all();
    expect($titles)->toContain('B Service');
    expect($titles)->not->toContain('A Service');
});
