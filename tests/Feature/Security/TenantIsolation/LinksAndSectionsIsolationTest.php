<?php

use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalLinkBlockController;
use App\Http\Requests\Api\Professional\Site\IndexLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\UpdateLinkBlockRequest;
use App\Models\Core\Site\Block;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
});

it('link index only returns blocks belonging to the authenticated professional', function () {
    [$a, $b] = createTwoTenants('brand');
    $now = now()->toDateTimeString();

    DB::table('site.blocks')->insert([
        [
            'id' => (string) Str::uuid(),
            'professional_id' => $a->id,
            'site_id' => $a->site->id,
            'block_group' => 'links',
            'block_type' => 'link',
            'title' => 'Secret A',
            'url' => 'https://a.example',
            'sort_order' => 0,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => (string) Str::uuid(),
            'professional_id' => $b->id,
            'site_id' => $b->site->id,
            'block_group' => 'links',
            'block_type' => 'link',
            'title' => 'B Link',
            'url' => 'https://b.example',
            'sort_order' => 0,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    // IndexLinkBlockRequest has no validation rules — createFrom() is safe here.
    $plainReq = tenantRequestAs($b);
    $req = IndexLinkBlockRequest::createFrom($plainReq);
    $req->setContainer(app());
    $req->attributes->set('professional', $b);

    $response = app(ProfessionalLinkBlockController::class)->index($req);
    $payload = $response->getData(true);

    // success() wraps via response()->json($data) — no 'data' envelope.
    $titles = collect($payload['blocks'] ?? [])->pluck('title')->all();
    expect($titles)->toContain('B Link');
    expect($titles)->not->toContain('Secret A');
});

it('link update refuses a block belonging to another professional site', function () {
    [$a, $b] = createTwoTenants('brand');
    $now = now()->toDateTimeString();

    $blockId = (string) Str::uuid();
    DB::table('site.blocks')->insert([
        'id' => $blockId,
        'professional_id' => $a->id,
        'site_id' => $a->site->id,
        'block_group' => 'links',
        'block_type' => 'link',
        'title' => 'Secret A',
        'url' => 'https://a.example',
        'sort_order' => 0,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // UpdateLinkBlockRequest validates url/title — abort_unless fires before validated() is reached.
    $plainReq = tenantRequestAs($b, ['title' => 'Pwned', 'url' => 'https://pwned.example'], 'PATCH');
    $req = UpdateLinkBlockRequest::createFrom($plainReq);
    $req->setContainer(app());
    $req->attributes->set('professional', $b);

    $block = Block::query()->findOrFail($blockId);

    // abort_unless($linkBlock->professional_id === $pro->id) fires before validation runs.
    expect(fn () => app(ProfessionalLinkBlockController::class)->update($req, $block))
        ->toThrow(HttpException::class);

    expect(DB::table('site.blocks')->where('id', $blockId)->value('title'))->toBe('Secret A');
});
