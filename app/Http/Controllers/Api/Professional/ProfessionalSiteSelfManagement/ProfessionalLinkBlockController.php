<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Professional\Site\DestroyLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\IndexLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\ReorderBlocksRequest;
use App\Http\Requests\Api\Professional\Site\StoreLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\UpdateLinkBlockRequest;
use App\Models\Core\Site\Block;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
class ProfessionalLinkBlockController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    public function index(IndexLinkBlockRequest $request)
    {
        $pro = $this->currentProfessional($request);

        return $this->success([
            'blocks' => $pro->linkBlocks()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(StoreLinkBlockRequest $request)
    {
        $pro = $this->currentProfessional($request);
        $site = $this->currentSite($pro);

        $data = $request->validated();

        $linkBlock = DB::transaction(function () use ($pro, $site, $data) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-links:{$site->id}"]);

            $maxSort = Block::query()
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->max('sort_order');

            $maxSort = is_null($maxSort) ? -1 : (int) $maxSort;

            $linkBlock = new Block([
                'block_group' => 'links',
                'block_type'  => 'link',
                'title'       => $data['title'],
                'url'         => $data['url'],
                'icon_key'    => $data['icon_key'] ?? null,
                'sort_order'  => $maxSort + 1,
                'is_active'   => $data['is_active'] ?? true,
                'settings'    => $data['settings'] ?? [],
            ]);

            $linkBlock->professional_id = $pro->id;
            $linkBlock->site_id = $site->id;
            $linkBlock->save();
            return $linkBlock->fresh();
        });

        return $this->success(['block' => $linkBlock], 201);
    }

    public function update(UpdateLinkBlockRequest $request, Block $linkBlock)
    {
        $pro = $this->currentProfessional($request);

        abort_unless(
            $linkBlock->professional_id === $pro->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );

        $data = $request->validated();
        unset($data['id']);

        $linkBlock->fill($data);
        $linkBlock->save();

        return $this->success(['block' => $linkBlock->fresh()]);
    }

    public function destroy(DestroyLinkBlockRequest $request, Block $linkBlock)
    {
        $request->validated();

        $pro = $this->currentProfessional($request);

        abort_unless(
            $linkBlock->professional_id === $pro->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );

        $linkBlock->delete();

        return $this->success(['deleted' => true]);
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

        return $this->success(['ok' => true]);
    }
}
