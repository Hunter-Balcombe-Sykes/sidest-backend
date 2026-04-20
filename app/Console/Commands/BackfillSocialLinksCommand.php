<?php

namespace App\Console\Commands;

use App\Models\Core\Site\Block;
use App\Services\Site\SocialLinkNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * One-shot backfill: tag existing link blocks whose icon_key matches a social
 * platform with `settings.platform` and (when extractable) `settings.handle`.
 *
 * Idempotent — safe to re-run. Skips any block already tagged.
 *
 * Why we have this:
 *   When the social_platforms registry was introduced, existing link blocks
 *   used icon_key='instagram' etc. but had no platform tag in settings. This
 *   command brings those rows up to the new shape so the brand UI can render
 *   them in social mode and analytics can group by platform.
 *
 * Run order:
 *   1. Always run with --dry-run first to preview the stats.
 *   2. Run for real once you've reviewed.
 *   3. Optional: --limit=N for a cautious first batch on large datasets.
 *
 * Security:
 *   - Per-chunk DB transaction so a partial chunk failure rolls back cleanly.
 *   - chunkById(200) keeps memory bounded.
 *   - Logging hygiene: warnings reference block ID + platform key only,
 *     never the full URL or handle.
 *   - Audit log line at start records the operator and run mode.
 *
 * See docs/social-links.md.
 */
class BackfillSocialLinksCommand extends Command
{
    protected $signature = 'sidest:backfill-social-links
        {--dry-run : Show what would change without writing}
        {--limit=0 : Process at most N rows (0 = unlimited)}';

    protected $description = 'Tag existing link blocks with settings.platform/handle for known social icons. Idempotent — safe to re-run.';

    public function handle(SocialLinkNormalizer $normalizer): int
    {
        $registry = config('sidest.social_platforms', []);
        $iconToPlatform = $this->buildIconToPlatformMap($registry);
        $socialIconKeys = array_keys($iconToPlatform);

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        // Audit log on start — gives us a record of who ran the backfill and when.
        // Operator identity is best-effort (works locally, may be empty in some
        // container environments — that's fine, the timestamp is what matters).
        Log::info('Backfill social links started', [
            'operator' => get_current_user() ?: 'unknown',
            'dry_run' => $dryRun,
            'limit' => $limit,
        ]);

        $stats = [
            'total' => 0,
            'already_tagged' => 0,
            'tagged_with_handle' => 0,
            'tagged_url_only' => 0,
            'url_normalized' => 0,
            'unmatched_host' => 0,
            'errors' => 0,
        ];

        $query = Block::query()
            ->where('block_group', 'links')
            ->whereIn('icon_key', $socialIconKeys)
            ->whereNotNull('url')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->chunkById(200, function ($blocks) use (&$stats, $iconToPlatform, $normalizer, $dryRun) {
            // Per-chunk transaction: if any single block update fails partway
            // through the chunk, roll the whole chunk back. Keeps the DB in a
            // consistent state and lets the operator re-run without partial damage.
            DB::transaction(function () use ($blocks, &$stats, $iconToPlatform, $normalizer, $dryRun) {
                foreach ($blocks as $block) {
                    $stats['total']++;

                    $settings = is_array($block->settings) ? $block->settings : [];

                    // Idempotency guard: skip rows already tagged. Lets us
                    // re-run the command after deploying new platforms or
                    // fixing edge cases without touching what's already done.
                    if (isset($settings['platform'])) {
                        $stats['already_tagged']++;

                        continue;
                    }

                    $platformKey = $iconToPlatform[$block->icon_key] ?? null;
                    if ($platformKey === null) {
                        $stats['errors']++;

                        continue;
                    }

                    try {
                        $normalized = $normalizer->normalize($platformKey, null, $block->url);
                    } catch (InvalidArgumentException $e) {
                        // URL doesn't match the platform's host_allowlist (e.g. a
                        // Linktree URL behind an Instagram icon). Leave the row
                        // alone — operator can investigate or fix manually.
                        $stats['unmatched_host']++;
                        $this->warn(sprintf('  Skipping block %s (%s): host mismatch', $block->id, $platformKey));
                        Log::warning('Backfill social links: host mismatch', [
                            'block_id' => (string) $block->id,
                            'platform' => $platformKey,
                        ]);

                        continue;
                    }

                    $settings['platform'] = $platformKey;
                    if ($normalized['handle'] !== null) {
                        $settings['handle'] = $normalized['handle'];
                        $stats['tagged_with_handle']++;
                    } else {
                        $stats['tagged_url_only']++;
                    }

                    $urlChanged = $normalized['url'] !== $block->url;
                    if ($urlChanged) {
                        $stats['url_normalized']++;
                    }

                    if (! $dryRun) {
                        $block->settings = $settings;
                        if ($urlChanged) {
                            $block->url = $normalized['url'];
                        }
                        $block->save();
                    }
                }
            });
        });

        $this->newLine();
        $this->info($dryRun ? 'DRY RUN — no changes written.' : 'Backfill complete.');
        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(fn ($v, $k) => [$k, $v])->all()
        );

        return self::SUCCESS;
    }

    /**
     * Build a reverse map of icon_key → platform_key from the registry, so we
     * can look up "which platform does this existing block's icon belong to?"
     * in O(1) per row.
     *
     * @param  array<string, array<string, mixed>>  $registry
     * @return array<string, string>
     */
    private function buildIconToPlatformMap(array $registry): array
    {
        $map = [];
        foreach ($registry as $platformKey => $config) {
            $map[$config['icon_key']] = $platformKey;
        }

        return $map;
    }
}
