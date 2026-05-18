<?php

namespace App\Console\Commands;

use App\Models\Core\FeatureFlagOverride;
use Illuminate\Console\Command;

/**
 * Hard-deletes feature flag overrides whose expires_at is in the past.
 * Runs daily to keep the overrides table lean and avoid stale entries
 * from accumulating over time.
 */
class PruneExpiredFeatureFlagOverridesCommand extends Command
{
    protected $signature = 'feature-flags:prune-expired';

    protected $description = 'Hard-delete feature flag overrides whose expires_at is in the past';

    public function handle(): int
    {
        $deleted = FeatureFlagOverride::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("Pruned {$deleted} expired feature flag override(s).");

        return self::SUCCESS;
    }
}
