<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Professional\Site\DestroyLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\IndexLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\ReorderBlocksRequest;
use App\Http\Requests\Api\Professional\Site\StoreLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\UpdateLinkBlockRequest;
use App\Models\Core\Site\Block;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
class ProfessionalLinkBlockController extends Controller
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    public function index(IndexLinkBlockRequest $request)
    {
        $pro = $this->currentProfessional($request);

        return response()->json([
            'blocks' => $pro->linkBlocks()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(StoreLinkBlockRequest $request)
    {
        $pro = $this->currentProfessional($request);
        $site = $this->currentSite($pro);

        $data = $request->validated();

        $block = DB::transaction(function () use ($pro, $site, $data) {
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

            $block->professional_id = $pro->id;
            $block->site_id = $site->id;
            $block->save();
            return $block->fresh();
        });

        return response()->json(['block' => $block], 201);
    }

    public function update(UpdateLinkBlockRequest $request, Block $block)
    {
        $pro = $this->currentProfessional($request);

        abort_unless(
            $block->professional_id === $pro->id &&
            $block->block_group === 'links' &&
            $block->block_type === 'link',
            404
        );

        $data = $request->validated();
        unset($data['id']);

        $block->fill($data);
        $block->save();

        return response()->json(['block' => $block->fresh()]);
    }

    public function destroy(DestroyLinkBlockRequest $request, Block $block)
    {
        $request->validated();

        $pro = $this->currentProfessional($request);

        abort_unless(
            $block->professional_id === $pro->id &&
            $block->block_group === 'links' &&
            $block->block_type === 'link',
            404
        );

        $block->delete();

        return response()->json(['deleted' => true]);
    }

    public function reorder(ReorderBlocksRequest $request)
    {
        $pro = $this->currentProfessional($request);

        $ids = array_values(array_unique($request->validated()['ids'] ?? []));

        DB::transaction(function () use ($pro, $ids) {

            $allIds = Block::query()
                ->where('professional_id', $pro->id)
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
                    abort(403, 'One or more blocks do not belong to you');
                }
            }

            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder  = array_merge($ids, $remaining);

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('professional_id', $pro->id)
                    ->where('block_group', 'links')
                    ->where('block_type', 'link')
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        return response()->json(['ok' => true]);
    }
}
