<?php

use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalSectionBlockController;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Locks in the lazy-sync + batched-visibility behaviour of the index endpoint.
 *
 * Before the optimisation, GET /api/sections always entered a write transaction
 * with a per-site advisory lock and ran one visibility check per section
 * (1–4 exists() queries each). This test guards the fast path: when the
 * stored blocks already cover every allowed section type with is_enabled=true,
 * no advisory lock is taken and no save() runs.
 */
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupMediaTables();
    setupServicesTable();
    setupProfessionalIntegrationsTable();
    shimPgAdvisoryLockForSqlite();
});

function seedAllowedSectionsForBrand(Professional $pro): array
{
    $allowed = config('partna.account_type_defaults.brand.allowed_sections', []);
    $ids = [];
    $now = now()->toDateTimeString();

    foreach ($allowed as $i => $type) {
        $id = (string) Str::uuid();
        DB::table('site.blocks')->insert([
            'id' => $id,
            'professional_id' => $pro->id,
            'site_id' => $pro->site->id,
            'block_group' => 'sections',
            'block_type' => $type,
            'sort_order' => $i,
            'is_active' => 0,
            'is_enabled' => 1,
            'settings' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $ids[$type] = $id;
    }

    return $ids;
}

function callSectionsIndex(Professional $pro)
{
    $req = tenantRequestAs($pro);

    return app(ProfessionalSectionBlockController::class)->index($req);
}

it('skips the sync write transaction when allowed sections already exist with is_enabled', function () {
    $pro = createBrandTenant('sections-fast-path');
    seedAllowedSectionsForBrand($pro);

    $writeStatements = 0;
    DB::connection('pgsql')->listen(function ($query) use (&$writeStatements) {
        $sql = strtolower($query->sql);
        if (str_starts_with($sql, 'update site.blocks')
            || str_starts_with($sql, 'insert into site.blocks')) {
            $writeStatements++;
        }
    });

    $response = callSectionsIndex($pro);

    expect($response->getStatusCode())->toBe(200);
    expect($writeStatements)->toBe(0);
});

it('runs sync exactly once when an allowed section row is missing', function () {
    $pro = createBrandTenant('sections-cold-path');

    // Seed all allowed sections except 'gallery' — the missing row must trigger sync.
    $allowed = config('partna.account_type_defaults.brand.allowed_sections', []);
    $now = now()->toDateTimeString();
    foreach ($allowed as $i => $type) {
        if ($type === 'gallery') {
            continue;
        }
        DB::table('site.blocks')->insert([
            'id' => (string) Str::uuid(),
            'professional_id' => $pro->id,
            'site_id' => $pro->site->id,
            'block_group' => 'sections',
            'block_type' => $type,
            'sort_order' => $i,
            'is_active' => 0,
            'is_enabled' => 1,
            'settings' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $response = callSectionsIndex($pro);

    expect($response->getStatusCode())->toBe(200);

    // Sync inserted the missing 'gallery' row.
    $galleryRow = DB::table('site.blocks')
        ->where('professional_id', $pro->id)
        ->where('block_group', 'sections')
        ->where('block_type', 'gallery')
        ->first();
    expect($galleryRow)->not->toBeNull();
    expect((int) $galleryRow->is_enabled)->toBe(1);
});

it('returns can_publish=true for sections whose requirements are met and false otherwise', function () {
    $pro = createBrandTenant('sections-visibility-batch');
    seedAllowedSectionsForBrand($pro);

    $response = callSectionsIndex($pro);
    $payload = $response->getData(true);

    $byType = collect($payload['sections'] ?? [])->keyBy('block_type');

    // Gallery has no images seeded → can_publish=false with a reason.
    expect($byType['gallery']['can_publish'])->toBeFalse();
    expect($byType['gallery']['requirement_reason'])->toContain('image');

    // 'shop' is in the default-true bucket (no requirement) → can_publish=true.
    expect($byType['shop']['can_publish'])->toBeTrue();
    expect($byType['shop']['requirement_reason'])->toBeNull();
});

it('does not query data-sources for section types absent from the response', function () {
    // Brand allowed_sections excludes 'documents'. The batch path should
    // therefore never run the SiteMedia documents-pool exists() query.
    $pro = createBrandTenant('sections-narrow-batch');
    seedAllowedSectionsForBrand($pro);

    $documentsPoolQueries = 0;
    DB::connection('pgsql')->listen(function ($query) use (&$documentsPoolQueries) {
        $sql = strtolower($query->sql);
        $bindings = $query->bindings;
        if (str_contains($sql, 'site.site_media')
            && in_array('documents', array_map('strval', $bindings), true)) {
            $documentsPoolQueries++;
        }
    });

    callSectionsIndex($pro);

    expect($documentsPoolQueries)->toBe(0);
});
