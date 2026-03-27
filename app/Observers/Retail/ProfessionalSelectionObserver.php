<?php

namespace App\Observers\Retail;

use App\Models\Retail\ProfessionalSelection;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProfessionalSelectionObserver
{
    public bool $afterCommit = true;

    public function __construct(private readonly NotificationPublisher $publisher) {}

    public function deleted(ProfessionalSelection $selection): void
    {
        try {
            $affiliateId = trim((string) ($selection->professional_id ?? ''));
            $productId   = trim((string) ($selection->brand_product_id ?? ''));
            if ($affiliateId === '' || $productId === '') {
                return;
            }

            $productTitle = DB::table('retail.brand_products')
                ->where('id', $productId)
                ->value('title');

            $label = $productTitle ? trim((string) $productTitle) : 'A product';

            $this->publisher->publish(
                professionalId: $affiliateId,
                frontendType: 'Warning',
                category: 'catalog_changes',
                title: 'Product removed from your store',
                body: "{$label} has been removed from your selected products.",
                dedupeKey: "catalog.selection_removed.{$productId}.{$affiliateId}",
                ctaUrl: '/account/store',
                retentionConfigKey: 'catalog_change',
            );
        } catch (\Throwable $e) {
            Log::warning('ProfessionalSelection deleted notification failed', [
                'selection_id' => $selection->id,
                'message'      => $e->getMessage(),
            ]);
        }
    }
}
