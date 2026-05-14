<?php

namespace App\Jobs\Shopify;

use App\Models\Commerce\AffiliateProductSelection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Chunked purge of affiliate curated selections after a brand uninstalls Shopify.
 *
 * Moved out of ShopifyAppUninstalledWebhookController to keep the webhook off
 * Shopify's ack deadline AND to bound the row-lock footprint — a single-statement
 * delete on a brand with 10K selections holds row locks for the whole table-scan
 * duration, which can block concurrent orders/paid webhook handlers that read
 * affiliate_product_selections during commission attribution.
 *
 * chunkById walks by primary-key cursor (delete-safe), and each chunk runs in its
 * own implicit transaction — so no single chunk holds locks for longer than
 * the time to delete CHUNK_SIZE rows.
 */
class PurgeAffiliateProductSelectionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    // Cover the full retry budget (30+90+300s) so a webhook replay during the
    // longest backoff still de-duplicates against the in-flight purge.
    public int $uniqueFor = 600;

    private const CHUNK_SIZE = 500;

    public function __construct(public readonly string $brandProfessionalId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return $this->brandProfessionalId;
    }

    public function backoff(): array
    {
        return [30, 90, 300];
    }

    public function handle(): void
    {
        $deleted = 0;

        AffiliateProductSelection::query()
            ->where('brand_professional_id', $this->brandProfessionalId)
            ->chunkById(self::CHUNK_SIZE, function ($chunk) use (&$deleted): void {
                // each->delete() so model events still fire (cache busts etc.).
                // Bulk delete would skip them and risk leaving observers stale.
                $chunk->each->delete();
                $deleted += $chunk->count();
            });

        Log::info('Purged affiliate product selections after Shopify uninstall.', [
            'brand_professional_id' => $this->brandProfessionalId,
            'deleted_count' => $deleted,
        ]);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('shopify.purge_affiliate_selections.failed', [
            'brand_professional_id' => $this->brandProfessionalId,
            'error' => $e->getMessage(),
        ]);
    }
}
