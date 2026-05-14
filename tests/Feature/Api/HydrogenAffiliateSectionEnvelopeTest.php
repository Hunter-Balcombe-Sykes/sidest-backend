<?php

use App\Http\Controllers\Api\Internal\HydrogenAffiliateController;
use App\Models\Core\Site\Block;
use App\Services\Cache\CacheLockService;
use Illuminate\Support\Str;

// Exercises the sectionEnvelope() helper via reflection so we can assert the
// block_id contract without wiring up the full show() DB chain.
function invokeEnvelope(HydrogenAffiliateController $controller, $sections, string $type, callable $data): array
{
    $method = new ReflectionMethod($controller, 'sectionEnvelope');
    $method->setAccessible(true);

    return $method->invoke($controller, $sections, $type, $data);
}

// The CacheLockService dep is required for show() but irrelevant to the
// section-envelope helper — these tests never enter the caching path. Pass
// a real instance so the constructor signature is satisfied without mocking.
function envelopeController(): HydrogenAffiliateController
{
    return new HydrogenAffiliateController(new CacheLockService);
}

it('omits block_id from the shop envelope when no block row exists', function () {
    $controller = envelopeController();
    $sections = collect(); // no blocks at all

    $result = invokeEnvelope($controller, $sections, 'shop', fn () => null);

    expect($result)
        ->toHaveKey('state', 'draft')
        ->toHaveKey('data', null)
        ->not->toHaveKey('block_id'); // absent, not null — Hydrogen guards on presence
});

it('includes block_id in the shop envelope when the block exists and is live', function () {
    $controller = envelopeController();
    $blockId = (string) Str::uuid();
    $block = new Block;
    $block->id = $blockId;
    $block->block_type = 'shop';
    $block->is_enabled = true;
    $block->is_active = true;
    $sections = collect(['shop' => $block]);

    $result = invokeEnvelope($controller, $sections, 'shop', fn () => null);

    expect($result)
        ->toHaveKey('state', 'live')
        ->toHaveKey('block_id', $blockId)
        ->toHaveKey('data', null);
});

it('includes block_id in the shop envelope when the block exists but is draft', function () {
    $controller = envelopeController();
    $blockId = (string) Str::uuid();
    $block = new Block;
    $block->id = $blockId;
    $block->block_type = 'shop';
    $block->is_enabled = true;
    $block->is_active = false;
    $sections = collect(['shop' => $block]);

    $result = invokeEnvelope($controller, $sections, 'shop', fn () => null);

    // Block exists (ID present), but section is toggled off — state='draft'.
    // block_id is still returned so Hydrogen knows the block is configured
    // but unpublished, rather than treating it as unconfigured.
    expect($result)
        ->toHaveKey('state', 'draft')
        ->toHaveKey('block_id', $blockId)
        ->toHaveKey('data', null);
});

it('treats is_active=true but is_enabled=false as draft (requirements gate)', function () {
    // The pro turned the section Live, but underlying data went away
    // (e.g. last gallery image deleted → SiteMediaObserver flipped
    // is_enabled to false). The public render path must hide it.
    $controller = envelopeController();
    $blockId = (string) Str::uuid();
    $block = new Block;
    $block->id = $blockId;
    $block->block_type = 'gallery';
    $block->is_enabled = false;
    $block->is_active = true;
    $sections = collect(['gallery' => $block]);

    $result = invokeEnvelope($controller, $sections, 'gallery', fn () => ['anything']);

    expect($result)
        ->toHaveKey('state', 'draft')
        ->toHaveKey('block_id', $blockId)
        ->toHaveKey('data', null);
});
