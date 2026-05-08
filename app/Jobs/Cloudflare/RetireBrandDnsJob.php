<?php

namespace App\Jobs\Cloudflare;

use App\Services\Cloudflare\CloudflareDnsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Removes the Cloudflare CNAME for a retired subdomain. Looks up the record by name
// (no stored record ID needed). Used when a brand renames their subdomain.
class RetireBrandDnsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $subdomain) {}

    public function handle(CloudflareDnsService $dns): void
    {
        if ($this->subdomain === '') {
            return;
        }

        $fqdn = $this->subdomain.'.'.config('partna.public_domain', 'partna.au');
        $record = $dns->findRecord('CNAME', $fqdn);

        if (! $record || ! isset($record['id'])) {
            Log::info('RetireBrandDnsJob: no record found for subdomain (already gone)', [
                'subdomain' => $this->subdomain,
                'fqdn' => $fqdn,
            ]);

            return;
        }

        $dns->deleteRecord((string) $record['id']);
    }
}
