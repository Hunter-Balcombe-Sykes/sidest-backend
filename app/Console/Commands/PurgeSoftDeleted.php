<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\SiteMedia;

class PurgeSoftDeleted extends Command
{
    protected $signature = 'comet:purge-soft-deletes {--days= : Override retention days}';
    protected $description = 'Permanently delete soft-deleted rows older than retention window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('comet.soft_delete_retention_days', 30));
        $cutoff = now()->subDays($days);

        $this->info("Purging soft-deleted rows older than {$days} days (before {$cutoff}).");

        $total = 0;

        // Customers
        $total += $this->purgeModel(Customer::class, $cutoff);

        // Services
        $total += $this->purgeModel(Service::class, $cutoff);

        // Site media (DB rows) — see note below about deleting storage objects too
        $total += $this->purgeModel(SiteMedia::class, $cutoff);

        $this->info("Done. Force-deleted {$total} rows.");

        return self::SUCCESS;
    }

    private function purgeModel(string $modelClass, Carbon $cutoff): int
    {
        $count = 0;

        $modelClass::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->orderBy('deleted_at')
            ->chunk(500, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $row->forceDelete(); // triggers model events
                    $count++;
                }
            });

        $this->line(class_basename($modelClass) . ": {$count}");
        return $count;
    }
}
