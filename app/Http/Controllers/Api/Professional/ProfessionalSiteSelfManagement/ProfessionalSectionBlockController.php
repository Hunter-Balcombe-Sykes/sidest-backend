<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Professional\Site\UpsertSectionBlockRequest;
use App\Models\Core\Site\Block;
use App\Services\Professional\AccountTypeDefaultsService;
use App\Services\Professional\SectionVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;

class ProfessionalSectionBlockController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function __construct(
        private readonly SectionVisibilityService $visibilityService,
        private readonly AccountTypeDefaultsService $defaultsService,
    ) {}

    public function index(Request $request)
    {
        $pro = $this->currentProfessional($request);

        $professionalType = mb_strtolower(trim((string) ($pro->professional_type ?? '')));
        $defaults = $this->defaultsService->resolveDefaults($professionalType);
        $allowedSections = $defaults['allowed_sections'] ?? config('comet.section_block_types', []);

        $sections = $pro->sectionBlocks()
            ->whereIn('block_type', $allowedSections)
            ->get();

        return $this->success([
            'sections' => $sections,
            'allowed_sections' => array_values($allowedSections),
        ]);
    }

    public function upsert(UpsertSectionBlockRequest $request, string $blockType)
    {
        $pro = $this->currentProfessional($request);

        $site = $this->currentSite($pro);

        $data = $request->validated();
        $existingBlock = Block::query()
            ->where('professional_id', $pro->id)
            ->where('site_id', $site->id)
            ->where('block_group', 'sections')
            ->where('block_type', $blockType)
            ->first();

        $nextIsActive = array_key_exists('is_active', $data)
            ? (bool) $data['is_active']
            : ($existingBlock ? (bool) $existingBlock->is_active : true);
        $currentlyIsActive = $existingBlock ? (bool) $existingBlock->is_active : false;
        $isEnabling = $nextIsActive && ! $currentlyIsActive;

        $professionalType = mb_strtolower(trim((string) ($pro->professional_type ?? '')));

        // ── Account-type section restrictions ────────────────────────────
        $defaults = $this->defaultsService->resolveDefaults($professionalType);
        $allowedSections = $defaults['allowed_sections'] ?? config('comet.section_block_types', []);
        if (! in_array($blockType, $allowedSections, true)) {
            return $this->error('This section is not available for your account type.', 403);
        }

        // ── Visibility requirements check ────────────────────────────────
        if ($isEnabling) {
            [$canBeVisible, $reason] = $this->visibilityService->checkVisibilityRequirements(
                (string) $pro->id,
                (string) $site->id,
                $blockType
            );
            if (! $canBeVisible) {
                return $this->error($reason, 422);
            }
        }

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

            if (array_key_exists('is_enabled', $data)) {
                $block->is_enabled = (bool) $data['is_enabled'];
                // Disabling also hides from public
                if (! $data['is_enabled']) {
                    $block->is_active = false;
                }
            }

            if (!$block->exists) {
                $maxSort = Block::query()
                    ->where('site_id', $site->id)
                    ->where('block_group', 'sections')
                    ->max('sort_order');

                $block->sort_order  = is_null($maxSort) ? 0 : ((int) $maxSort + 1);
                $block->is_active   = $data['is_active'] ?? true;
                $block->is_enabled  = $data['is_enabled'] ?? true;
                $block->settings    = $data['settings'] ?? [];
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


        return $this->success([
            'section' => $block->fresh(),
        ], $block->wasRecentlyCreated ? 201 : 200);
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
            return $this->success(['ok' => true]);
        }

        $block->is_active = false;
        $block->save();

        return $this->success(['ok' => true]);
    }
}
