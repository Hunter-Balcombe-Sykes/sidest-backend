<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Professional\Site\UpsertSectionBlockRequest;
use App\Models\Core\Site\Block;
use App\Services\Professional\AccountTypeDefaultsService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;

class ProfessionalSectionBlockController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function __construct(
        private readonly AccountTypeDefaultsService $defaultsService,
    ) {}

    public function index(Request $request)
    {
        $pro = $this->currentProfessional($request);
        $site = $this->currentSite($pro);

        $professionalType = mb_strtolower(trim((string) ($pro->professional_type ?? '')));
        $defaults = $this->defaultsService->resolveDefaults($professionalType);
        $allowedSections = $defaults['allowed_sections'] ?? config('comet.section_block_types', []);

        $this->syncAllowedSections($pro->id, $site->id, $allowedSections);

        $sections = $pro->sectionBlocks()
            ->where('site_id', $site->id)
            ->whereIn('block_type', $allowedSections)
            ->where('is_enabled', true)
            ->where('is_active', true)
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
        $professionalType = mb_strtolower(trim((string) ($pro->professional_type ?? '')));

        // ── Account-type section restrictions ────────────────────────────
        $defaults = $this->defaultsService->resolveDefaults($professionalType);
        $allowedSections = $defaults['allowed_sections'] ?? config('comet.section_block_types', []);
        if (! in_array($blockType, $allowedSections, true)) {
            return $this->error('This section is not available for your account type.', 403);
        }

        $this->syncAllowedSections($pro->id, $site->id, $allowedSections);

        $block = DB::transaction(function () use ($pro, $site, $data, $blockType) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$site->id}"]);

            $block = Block::query()->firstOrNew([
                'professional_id' => $pro->id,
                'site_id'         => $site->id,
                'block_group'     => 'sections',
                'block_type'      => $blockType,
            ]);

            if (!$block->exists) {
                $existingCount = Block::query()
                    ->where('site_id', $site->id)
                    ->where('block_group', 'sections')
                    ->count();
                $block->sort_order  = (int) $existingCount;
                $block->settings    = $data['settings'] ?? [];
            }

            // Account-allowed sections are always enabled and active.
            $block->is_enabled = true;
            $block->is_active = true;

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
        $professionalType = mb_strtolower(trim((string) ($pro->professional_type ?? '')));
        $defaults = $this->defaultsService->resolveDefaults($professionalType);
        $allowedSections = $defaults['allowed_sections'] ?? config('comet.section_block_types', []);
        if (! in_array($blockType, $allowedSections, true)) {
            return $this->error('This section is not available for your account type.', 403);
        }

        $this->syncAllowedSections($pro->id, $site->id, $allowedSections);

        return $this->success(['ok' => true]);
    }

    /**
     * Ensure every account-type-allowed section exists and is always enabled + active.
     *
     * @param  array<int, string>  $allowedSections
     */
    private function syncAllowedSections(string $professionalId, string $siteId, array $allowedSections): Collection
    {
        $orderedAllowed = array_values(array_unique(array_filter($allowedSections, static fn ($value) => is_string($value))));

        return DB::transaction(function () use ($professionalId, $siteId, $orderedAllowed) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$siteId}"]);

            $blocks = Block::query()
                ->where('professional_id', $professionalId)
                ->where('site_id', $siteId)
                ->where('block_group', 'sections')
                ->whereIn('block_type', $orderedAllowed)
                ->get()
                ->keyBy('block_type');

            foreach ($orderedAllowed as $sortOrder => $blockType) {
                $block = $blocks->get($blockType) ?? new Block([
                    'professional_id' => $professionalId,
                    'site_id' => $siteId,
                    'block_group' => 'sections',
                    'block_type' => $blockType,
                ]);

                if (! $block->exists) {
                    $block->settings = [];
                }

                $block->sort_order = $sortOrder;
                $block->is_enabled = true;
                $block->is_active = true;
                $block->save();
                $blocks->put($blockType, $block);
            }

            return $blocks->values();
        });
    }
}
