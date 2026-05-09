<?php

namespace App\Jobs\Cloudflare;

use App\Services\Cloudflare\CloudflareKvService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Removes a stale KV routing entry when a professional renames their handle.
// Without this, the old <handle>.partna.au subdomain continues to resolve
// via the stale entry — and could route to a future owner's profile if the
// handle is later claimed by someone else.
class RetireSubdomainFromKvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $handle) {}

    public function handle(CloudflareKvService $kv): void
    {
        if ($this->handle === '') {
            return;
        }

        try {
            $kv->delete($this->handle);
        } catch (\Throwable $e) {
            Log::warning('RetireSubdomainFromKvJob: delete failed', [
                'handle' => $this->handle,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
