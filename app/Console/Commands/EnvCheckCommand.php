<?php

namespace App\Console\Commands;

use App\Services\Diagnostics\EnvCheckService;
use Illuminate\Console\Command;

/**
 * Verify that every required (and recommended) config value is set.
 *
 * Thin CLI wrapper around {@see EnvCheckService}, which is the single
 * source of truth for the required + recommended config maps. Same logic
 * is reused by the /api/internal/env-check HTTP endpoint.
 *
 * Exit codes:
 *   0  — every required key is set (recommended-tier misses warn only)
 *   1  — at least one required key is missing
 */
class EnvCheckCommand extends Command
{
    protected $signature = 'env:check {--json : Output a machine-readable JSON report}';

    protected $description = 'Verify required + recommended config (env vars) are set';

    public function handle(EnvCheckService $service): int
    {
        $report = $service->generate();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));

            return $report['status'] === 'ok' ? 0 : 1;
        }

        $this->emitTable('Required', EnvCheckService::REQUIRED, $report['required_missing']);
        $this->emitTable('Recommended', EnvCheckService::RECOMMENDED, $report['recommended_missing']);

        if ($report['status'] === 'ok') {
            $this->info('OK — all required config is set.');
            if ($report['recommended_missing'] !== []) {
                $this->warn(count($report['recommended_missing']).' recommended key(s) missing (above).');
            }

            return 0;
        }

        $this->error('FAIL — '.count($report['required_missing']).' required key(s) missing.');

        return 1;
    }

    /**
     * @param  array<string, array<string, string>>  $map
     * @param  list<string>  $missing
     */
    private function emitTable(string $tier, array $map, array $missing): void
    {
        $missingSet = array_flip($missing);
        $this->newLine();
        $this->line("<options=bold>{$tier}</>");

        foreach ($map as $group => $entries) {
            $rows = [];
            foreach ($entries as $path => $envLabel) {
                $isMissing = isset($missingSet[$path]);
                $rows[] = [
                    $isMissing ? '✗' : '✓',
                    $path,
                    $envLabel,
                ];
            }
            $this->line("  <fg=cyan>{$group}</>");
            $this->table(['', 'Config path', 'Env var'], $rows);
        }
    }
}
