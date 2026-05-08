<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\ProvisionBrandDnsJob;
use App\Models\Core\Professional\Professional;
use Illuminate\Console\Command;

class BackfillBrandDnsCommand extends Command
{
    protected $signature = 'partna:backfill-brand-dns
                            {--queue : Dispatch via the queue (default: synchronous)}';

    protected $description = 'Provisions Cloudflare CNAME for every brand professional with a site row. Idempotent.';

    public function handle(): int
    {
        $brands = Professional::query()
            ->where('professional_type', 'brand')
            ->whereHas('site')
            ->pluck('id');

        $this->info("Found {$brands->count()} brand(s) to backfill.");

        $useQueue = (bool) $this->option('queue');

        foreach ($brands as $id) {
            if ($useQueue) {
                ProvisionBrandDnsJob::dispatch((string) $id);
                $this->line("  queued: {$id}");
            } else {
                ProvisionBrandDnsJob::dispatchSync((string) $id);
                $this->line("  done:   {$id}");
            }
        }

        $this->info('Backfill complete.');

        return self::SUCCESS;
    }
}
