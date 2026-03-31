<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Professional\Site\UpsertSectionBlockRequest;
use App\Models\Core\Site\Block;
use App\Services\Professional\AccountTypeDefaultsService;
use App\Services\Professional\SectionVisibilityService;
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
        private readonly SectionVisibilityService $visibilityService,
    ) {}

    public function index(Request $request)
    {
        $pro = $this->currentProfessional($request);
        $site = $this->currentSite($pro);

        $professionalType = mb_strtolower(trim((string) ($pro->professional_type ?? '')));
        $defaults = $this->defaultsService->resolveDefaults($professionalType);
        $allowedSections = $defaults['allowed_sections'] ?? config('comet.section_block_types', []);
        $allSections = config('comet.section_block_types', []);
        $unavailableSections = array_values(array_diff($allSections, $allowedSections));

        $this->syncAllowedSections($pro->id, $site->id, $allowedSections);

        $sections = $pro->sectionBlocks()
            ->where('site_id', $site->id)
            ->whereIn('block_type', $allowedSections)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->get();

        return $this->success([
            'sections' => $sections->map(fn (Block $section) => $this->serializeSection($section))->values(),
            'allowed_sections' => array_values($allowedSections),
            'unavailable_sections' => $unavailableSections,
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
        $existingBlock = Block::query()
            ->where('professional_id', $pro->id)
            ->where('site_id', $site->id)
            ->where('block_group', 'sections')
            ->where('block_type', $blockType)
            ->first();

        $requestedPublicationState = is_string($data['publication_state'] ?? null)
            ? mb_strtolower(trim((string) $data['publication_state']))
            : null;
        $nextIsLive = match (true) {
            $requestedPublicationState === 'live' => true,
            $requestedPublicationState === 'draft' => false,
            array_key_exists('is_active', $data) => (bool) $data['is_active'],
            $existingBlock !== null => (bool) $existingBlock->is_active,
            default => false,
        };
        $currentlyIsLive = $existingBlock ? (bool) $existingBlock->is_active : false;
        $isPublishing = $nextIsLive && ! $currentlyIsLive;

        // Keep setup requirements tied to publishing Live state.
        if ($isPublishing) {
            [$canBeVisible, $reason] = $this->visibilityService->checkVisibilityRequirements(
                (string) $pro->id,
                (string) $site->id,
                $blockType
            );
            if (! $canBeVisible) {
                return $this->error($reason, 422);
            }
        }

        $block = DB::transaction(function () use ($pro, $site, $data, $blockType, $nextIsLive) {
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

            // Account-allowed sections are always available in account pages.
            $block->is_enabled = true;
            $block->is_active = $nextIsLive;

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
            'section' => $this->serializeSection($block->fresh()),
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
        $block = Block::query()
            ->where('professional_id', $pro->id)
            ->where('site_id', $site->id)
            ->where('block_group', 'sections')
            ->where('block_type', $blockType)
            ->first();

        if ($block) {
            // DELETE behaves as "move to draft" for backward compatibility.
            $block->is_enabled = true;
            $block->is_active = false;
            $block->save();
        }

        return $this->success([
            'ok' => true,
            'section' => $block ? $this->serializeSection($block->fresh()) : null,
        ]);
    }

    /**
     * Ensure every account-type-allowed section exists and is always enabled.
     *
     * @param  array<int, string>  $allowedSections
     */
    private function syncAllowedSections(string $professionalId, string $siteId, array $allowedSections): Collection
    {
        $orderedAllowed = array_values(array_unique(array_filter($allowedSections, static fn ($value) => is_string($value))));

        return DB::transaction(function () use ($professionalId, $siteId, $orderedAllowed) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$siteId}"]);

            $allBlocks = Block::query()
                ->where('professional_id', $professionalId)
                ->where('site_id', $siteId)
                ->where('block_group', 'sections')
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->get()
                ->values();

            $blocksByType = $allBlocks->keyBy('block_type');

            $orderedBlocks = new Collection();

            foreach ($orderedAllowed as $blockType) {
                $block = $blocksByType->get($blockType) ?? new Block([
                    'professional_id' => $professionalId,
                    'site_id' => $siteId,
                    'block_group' => 'sections',
                    'block_type' => $blockType,
                ]);

                if (! $block->exists) {
                    $block->settings = [];
                    $block->is_active = false;
                }

                $block->is_enabled = true;
                $blocksByType->put($blockType, $block);
                $orderedBlocks->push($block);
            }

            $maxSort = Block::query()
                ->where('professional_id', $professionalId)
                ->where('site_id', $siteId)
                ->where('block_group', 'sections')
                ->max('sort_order');

            $offset = (int) (is_null($maxSort) ? 0 : $maxSort) + 1000;

            // Two-pass update to avoid transient unique collisions while reshuffling.
            foreach ($allBlocks as $index => $block) {
                $block->sort_order = $offset + $index;
                $block->save();
            }

            $nextTempSort = $offset + $allBlocks->count();
            foreach ($orderedBlocks as $block) {
                if (! $block->exists) {
                    $block->sort_order = $nextTempSort++;
                    $block->save();
                }
            }

            foreach ($orderedBlocks as $sortOrder => $block) {
                $block->sort_order = $sortOrder;
                $block->save();
            }

            $allowedSet = array_flip($orderedAllowed);
            $nextSortOrder = $orderedBlocks->count();
            foreach ($allBlocks as $block) {
                if (isset($allowedSet[$block->block_type])) {
                    continue;
                }

                $block->sort_order = $nextSortOrder++;
                $block->save();
            }

            return new Collection($orderedBlocks->values()->all());
        });
    }

    private function serializeSection(Block $section): array
    {
        $payload = $section->toArray();
        $isLive = (bool) ($section->is_active ?? false);
        $payload['publication_state'] = $isLive ? 'live' : 'draft';
        $payload['is_live'] = $isLive;
        return $payload;
    }
}
