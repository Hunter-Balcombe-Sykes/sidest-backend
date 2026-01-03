<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Professional\Site\ReorderBlocksRequest;
use App\Http\Requests\Api\Professional\Site\UpsertSectionBlockRequest;
use App\Models\Core\Site\Block;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;

class ProfessionalSectionBlockController extends Controller
{

    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    public function index(Request $request)
    {
        $pro = $this->currentProfessional($request);

        return response()->json([
            'sections' => $pro->sectionBlocks()->get(),
        ]);
    }

    public function upsert(UpsertSectionBlockRequest $request, string $blockType)
    {
        $pro = $this->currentProfessional($request);

        $site = $this->currentSite($pro);

        $data = $request->validated();

        $block = DB::transaction(function () use ($pro, $site, $data, $blockType) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$site->id}"]);

            $block = Block::query()->firstOrNew([
                'professional_id' => $pro->id,
                'site_id'         => $site->id,
                'block_group'     => 'sections',
                'block_type'      => $blockType,
            ]);

            if (array_key_exists('is_active', $data)) {
                $block->is_active = (bool) $data['is_active'];
            }

            if (!$block->exists) {
                $maxSort = Block::query()
                    ->where('site_id', $site->id)
                    ->where('block_group', 'sections')
                    ->max('sort_order');

                $block->sort_order = is_null($maxSort) ? 0 : ((int) $maxSort + 1);
                $block->is_active  = $data['is_active'] ?? true;
                $block->settings   = $data['settings'] ?? [];
            }

            // merge settings (PATCH semantics)
            if (array_key_exists('settings', $data)) {
                $existing = is_array($block->settings) ? $block->settings : [];
                $incoming = is_array($data['settings']) ? $data['settings'] : [];
                $block->settings = array_replace_recursive($existing, $incoming);
            } elseif (!$block->exists) {
                $block->settings = [];
            }

            $block->save();
            return $block->fresh();
        });

        // Keep professional.bio in sync with the "bio" section text (only when text was sent)
        if (
            $blockType === 'bio'
            && array_key_exists('settings', $data)
            && is_array($data['settings'])
            && array_key_exists('text', $data['settings'])
        ) {
            $pro->bio = data_get($block->settings, 'text'); // merged + saved value
            $pro->save();
        }


        return response()->json([
            'section' => $block->fresh(),
        ], $block->wasRecentlyCreated ? 201 : 200);
    }

    public function reorder(ReorderBlocksRequest $request)
    {
        $pro = $this->currentProfessional($request);

        $ids = array_values(array_unique($request->validated()['ids'] ?? []));

        DB::transaction(function () use ($pro, $ids) {

            $allIds = Block::query()
                ->where('professional_id', $pro->id)
                ->where('block_group', 'sections')
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);

            foreach ($ids as $id) {
                if (!isset($allSet[$id])) {
                    abort(403, 'One or more sections do not belong to you');
                }
            }

            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder  = array_merge($ids, $remaining);

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('professional_id', $pro->id)
                    ->where('block_group', 'sections')
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        return response()->json(['ok' => true]);
    }


    public function remove(Request $request, string $blockType)
    {
        $pro = $this->currentProfessional($request);

        $site = $this->currentSite($pro);

        $block = Block::query()
            ->where('professional_id', $pro->id)
            ->where('site_id', $site->id)
            ->where('block_group', 'sections')
            ->where('block_type', $blockType)
            ->first();

        if (!$block) {
            // idempotent delete (nice for UI)
            return response()->json(['ok' => true]);
        }

        $block->is_active = false;
        $block->save();

        return response()->json(['ok' => true]);
    }
}
