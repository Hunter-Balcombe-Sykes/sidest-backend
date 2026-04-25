<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\Site\ReorderBlocksRequest;
use App\Http\Requests\Api\Professional\Site\UpsertSectionBlockRequest;
use App\Models\Core\Site\Block;
use App\Services\Professional\AccountTypeDefaultsService;
use App\Services\Professional\SectionVisibilityService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Manages site section visibility (gallery, services, shop, booking, bio). Account-type restrictions apply.
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
        $allowedSections = $defaults['allowed_sections'] ?? config('sidest.section_block_types', []);
        $allSections = config('sidest.section_block_types', []);
        $unavailableSections = array_values(array_diff($allSections, $allowedSections));

        $this->syncAllowedSections($pro->id, $site->id, $allowedSections);

        // Returns both published and drafted sections so the dashboard can
        // render the Draft → Live toggle for each. The is_enabled filter
        // used to hide drafts but was always-true for allowed sections
        // anyway — dropping it is explicit, not behavioural.
        $sections = $pro->sectionBlocks()
            ->where('site_id', $site->id)
            ->whereIn('block_type', $allowedSections)
            ->orderBy('sort_order')
            ->get();

        return $this->success([
            'sections' => $sections
                ->map(fn (Block $section) => $this->serializeSection($section, (string) $pro->id, (string) $site->id))
                ->values(),
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
        $allowedSections = $defaults['allowed_sections'] ?? config('sidest.section_block_types', []);
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
        // For countdown, pass through the incoming settings — its requirement
        // (a valid timeline) lives in the payload itself, not in an external
        // resource, so first-time publish with timeline + live in the same
        // request must see the pending values, not the pre-save stored ones.
        if ($isPublishing) {
            [$canBeVisible, $reason] = $this->visibilityService->checkVisibilityRequirements(
                (string) $pro->id,
                (string) $site->id,
                $blockType,
                is_array($data['settings'] ?? null) ? $data['settings'] : null,
            );
            if (! $canBeVisible) {
                return $this->error($reason, 422);
            }
        }

        $block = DB::transaction(function () use ($pro, $site, $data, $blockType, $nextIsLive) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$site->id}"]);

            $block = Block::query()->firstOrNew([
                'professional_id' => $pro->id,
                'site_id' => $site->id,
                'block_group' => 'sections',
                'block_type' => $blockType,
            ]);

            if (! $block->exists) {
                $existingCount = Block::query()
                    ->where('site_id', $site->id)
                    ->where('block_group', 'sections')
                    ->count();
                $block->sort_order = (int) $existingCount;
                $block->settings = $data['settings'] ?? [];
            }

            // Account-allowed sections are always available in account pages.
            $block->is_enabled = true;
            $block->is_active = $nextIsLive;

            // merge settings (PATCH semantics)
            if (array_key_exists('settings', $data)) {
                $existing = is_array($block->settings) ? $block->settings : [];
                $incoming = is_array($data['settings']) ? $data['settings'] : [];
                $block->settings = array_replace_recursive($existing, $incoming);
            } elseif (! $block->exists) {
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

    /**
     * Reorder section blocks for the current professional. Accepts an `ids` array
     * representing the new order; any sections owned by this site that aren't in
     * the array keep their current relative order and follow the supplied ids.
     *
     * The two-pass renumber (offset by max+1000, then 0..n) avoids transient
     * unique-violation collisions if a unique index on sort_order is ever added.
     */
    public function reorder(ReorderBlocksRequest $request)
    {
        $pro = $this->currentProfessional($request);
        $site = $this->currentSite($pro);

        $ids = array_values(array_unique($request->validated()['ids'] ?? []));

        DB::transaction(function () use ($pro, $site, $ids) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$site->id}"]);

            $allIds = Block::query()
                ->where('professional_id', $pro->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'sections')
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);

            foreach ($ids as $id) {
                if (! isset($allSet[$id])) {
                    abort(403, 'One or more sections do not belong to you');
                }
            }

            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder = array_merge($ids, $remaining);
            $offset = (int) Block::query()
                ->where('professional_id', $pro->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'sections')
                ->max('sort_order') + 1000;

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('professional_id', $pro->id)
                    ->where('site_id', $site->id)
                    ->where('block_group', 'sections')
                    ->where('id', $id)
                    ->update(['sort_order' => $offset + $i]);
            }

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('professional_id', $pro->id)
                    ->where('site_id', $site->id)
                    ->where('block_group', 'sections')
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        return $this->success(['ok' => true]);
    }

    public function remove(Request $request, string $blockType)
    {
        $pro = $this->currentProfessional($request);
        $site = $this->currentSite($pro);
        $professionalType = mb_strtolower(trim((string) ($pro->professional_type ?? '')));
        $defaults = $this->defaultsService->resolveDefaults($professionalType);
        $allowedSections = $defaults['allowed_sections'] ?? config('sidest.section_block_types', []);
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
                    $block->is_active = false;
                }

                $block->sort_order = $sortOrder;
                $block->is_enabled = true;
                $block->save();
                $blocks->put($blockType, $block);
            }

            return $blocks->values();
        });
    }

    private function serializeSection(Block $section, ?string $professionalId = null, ?string $siteId = null): array
    {
        $payload = $section->toArray();
        $isLive = (bool) ($section->is_active ?? false);
        $payload['publication_state'] = $isLive ? 'live' : 'draft';
        $payload['is_live'] = $isLive;

        // Expose the visibility gate state to the frontend so the Publish
        // button can disable + show its tooltip reason without duplicating
        // the rule set. Only queried when the ids are provided (the index
        // action supplies them; upsert/remove reuse this serializer post-
        // mutation and can skip the check since they already enforced it).
        if ($professionalId !== null && $siteId !== null) {
            [$canPublish, $reason] = $this->visibilityService->checkVisibilityRequirements(
                $professionalId,
                $siteId,
                (string) $section->block_type,
            );
            $payload['can_publish'] = $canPublish;
            $payload['requirement_reason'] = $reason;
        }

        return $payload;
    }
}
