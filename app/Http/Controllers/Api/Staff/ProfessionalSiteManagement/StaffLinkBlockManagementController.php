<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Staff\ProfessionalSite\Links\StaffReorderLinkRequest;
use App\Http\Requests\Api\Staff\ProfessionalSite\Links\StaffStoreLinkRequest;
use App\Http\Requests\Api\Staff\ProfessionalSite\Links\StaffUpdateLinkRequest;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Block;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StaffLinkBlockManagementController extends Controller
{
    use ResolveCurrentSite;
    public function index(Professional $professional): JsonResponse
    {
        return response()->json([
            'blocks' => $professional->linkBlocks()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(StaffStoreLinkRequest $request, Professional $professional): JsonResponse
    {
        $professional->loadMissing('site');
        $site = $this->currentSite($professional);

        $data = $request->validated();

        $block = DB::transaction(function () use ($professional, $site, $data) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-links:{$site->id}"]);

            $maxSort = Block::query()
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->max('sort_order');

            $maxSort = is_null($maxSort) ? -1 : (int) $maxSort;

            $block = new Block([
                'block_group' => 'links',
                'block_type'  => 'link',
                'title'       => $data['title'],
                'url'         => $data['url'],
                'icon_key'    => $data['icon_key'] ?? null,
                'sort_order'  => $maxSort + 1,
                'is_active'   => $data['is_active'] ?? true,
                'settings'    => $data['settings'] ?? [],
            ]);
            $block->professional_id = $professional->id;
            $block->site_id = $site->id;
            $block->save();
            return $block->fresh();
        });

        return response()->json(['block' => $block], 201);
    }

    public function update(StaffUpdateLinkRequest $request, Professional $professional, Block $block): JsonResponse
    {
        // scoped binding guarantees ownership, but still enforce correct kind of block
        abort_unless(
            $block->professional_id === $professional->id &&
            $block->block_group === 'links' &&
            $block->block_type === 'link',
            404
        );

        $block->fill($request->validated());
        $block->save();

        return response()->json(['block' => $block->fresh()]);
    }

    public function destroy(Professional $professional, Block $block): JsonResponse
    {
        abort_unless(
            $block->professional_id === $professional->id &&
            $block->block_group === 'links' &&
            $block->block_type === 'link',
            404
        );

        $block->delete();

        return response()->json(['deleted' => true]);
    }

    public function reorder(StaffReorderLinkRequest $request, Professional $professional): JsonResponse
    {
        $ids = array_values(array_unique($request->validated()['ids'] ?? []));

        DB::transaction(function () use ($professional, $ids) {

            $allIds = Block::query()
                ->where('professional_id', $professional->id)
                ->where('block_group', 'links')
                ->where('block_type', 'link')
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);

            foreach ($ids as $id) {
                if (!isset($allSet[$id])) {
                    abort(403, 'One or more blocks do not belong to professional');
                }
            }

            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder  = array_merge($ids, $remaining);

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('professional_id', $professional->id)
                    ->where('block_group', 'links')
                    ->where('block_type', 'link')
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        return response()->json(['ok' => true]);
    }

}
