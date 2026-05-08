<?php

namespace App\Console\Commands;

use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\SiteMedia;
use App\Services\Professional\AccountDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

// V2: Hard-deletes soft-deleted rows (customers, services, media) past retention
// window, AND hard-deletes professionals whose self-service deletion grace period
// has elapsed (via AccountDeletionService).
class PurgeSoftDeleted extends Command
{
    protected $signature = 'partna:purge-soft-deletes {--days= : Override retention days}';

    protected $description = 'Permanently delete soft-deleted rows and pending-deletion professionals older than retention window.';

    public function handle(AccountDeletionService $deletionService): int
    {
        $days = (int) ($this->option('days') ?: config('partna.soft_delete_retention_days', 30));
        $cutoff = now()->subDays($days);

        $this->info("Purging soft-deleted rows older than {$days} days (before {$cutoff}).");

        $total = 0;

        $total += $this->purgeModel(Customer::class, $cutoff);
        $total += $this->purgeModel(Service::class, $cutoff);
        $total += $this->purgeModel(SiteMedia::class, $cutoff);

        $this->info("Done with soft deletes. Force-deleted {$total} rows.");

        // Pending-deletion professionals past grace period
        $this->purgePendingDeletionProfessionals($cutoff, $deletionService);

        return self::SUCCESS;
    }

    private function purgeModel(string $modelClass, Carbon $cutoff): int
    {
        $count = 0;
        $failed = 0;

        $modelClass::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->orderBy('deleted_at')
            ->chunk(500, function ($rows) use (&$count, &$failed) {
                foreach ($rows as $row) {
                    try {
                        $row->forceDelete();
                        $count++;
                    } catch (\Throwable $e) {
                        $failed++;
                        report($e);
                    }
                }
            });

        $this->line(class_basename($modelClass).": {$count} purged, {$failed} failed.");

        return $count;
    }

    /**
     * Hard-delete professionals whose grace period has elapsed. Each is handled
     * via AccountDeletionService::purge() which calls Supabase Admin API first.
     * Failures are logged to the audit table and retried on the next daily run.
     */
    private function purgePendingDeletionProfessionals(Carbon $cutoff, AccountDeletionService $deletionService): void
    {
        $purged = 0;
        $failed = 0;

        Professional::query()
            ->where('status', 'pending_deletion')
            ->where('deletion_confirmed_at', '<', $cutoff)
            ->orderBy('deletion_confirmed_at')
            ->chunk(100, function ($professionals) use ($deletionService, &$purged, &$failed) {
                foreach ($professionals as $professional) {
                    if ($deletionService->purge($professional)) {
                        $purged++;
                    } else {
                        $failed++;
                    }
                }
            });

        $this->line("PendingDeletion professionals: {$purged} purged, {$failed} failed (will retry next run).");
    }
}
