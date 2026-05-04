<?php

use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalServiceCategoryController;
use App\Models\Core\Professional\ServiceCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.service_categories (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        title TEXT NULL,
        sort_order INTEGER NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

it('service category show refuses a category belonging to another professional', function () {
    [$a, $b] = createTwoTenants('affiliate');

    $categoryId = (string) Str::uuid();
    DB::table('site.service_categories')->insert([
        'id' => $categoryId,
        'professional_id' => $a->id,
        'title' => 'A secret grouping',
        'sort_order' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $category = ServiceCategory::query()->findOrFail($categoryId);
    $req = tenantRequestAs($b);

    expect(fn () => app(ProfessionalServiceCategoryController::class)->show($req, $category))
        ->toThrow(AuthorizationException::class);
});

it('service category destroy refuses a category belonging to another professional', function () {
    [$a, $b] = createTwoTenants('affiliate');

    $categoryId = (string) Str::uuid();
    DB::table('site.service_categories')->insert([
        'id' => $categoryId,
        'professional_id' => $a->id,
        'title' => 'A private category',
        'sort_order' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $category = ServiceCategory::query()->findOrFail($categoryId);
    $req = tenantRequestAs($b, [], 'DELETE');

    expect(fn () => app(ProfessionalServiceCategoryController::class)->destroy($req, $category))
        ->toThrow(AuthorizationException::class);

    // Category must still exist, and still belong to A.
    $row = DB::table('site.service_categories')->where('id', $categoryId)->first();
    expect($row)->not->toBeNull();
    expect($row->deleted_at)->toBeNull();
    expect($row->professional_id)->toBe($a->id);
});

it('service category index only returns the authenticated professionals categories', function () {
    [$a, $b] = createTwoTenants('affiliate');

    DB::table('site.service_categories')->insert([
        ['id' => (string) Str::uuid(), 'professional_id' => $a->id, 'title' => 'A Category', 'sort_order' => 0, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
        ['id' => (string) Str::uuid(), 'professional_id' => $b->id, 'title' => 'B Category', 'sort_order' => 0, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
    ]);

    $req = tenantRequestAs($b);
    $response = app(ProfessionalServiceCategoryController::class)->index($req);
    $payload = $response->getData(true);

    $titles = collect($payload['categories'] ?? [])->pluck('title')->all();
    expect($titles)->toContain('B Category');
    expect($titles)->not->toContain('A Category');
});
