<?php

namespace App\Jobs\Cloudflare;

use App\Jobs\Concerns\HasCloudflareRetryPolicy;
use App\Services\Cloudflare\CloudflareDnsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Removes the Cloudflare CNAME for a retired subdomain. Looks up the record by name
// (no stored record ID needed). Used when a brand renames their subdomain.
class RetireBrandDnsJob implements ShouldQueue
{
    use Dispatchable, HasCloudflareRetryPolicy, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function __construct(public readonly string $subdomain)
    {
        $this->onQueue('integrations');
    }

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

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('cloudflare.retire_brand_dns.failed', [
            'subdomain' => $this->subdomain,
            'error' => $e->getMessage(),
        ]);
    }
}
