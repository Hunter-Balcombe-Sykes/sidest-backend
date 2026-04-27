<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Models\Retail\CommissionPayout;
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use App\Services\Professional\BrandPartnerLinkService;
use App\Services\Professional\DTO\DisconnectRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Brand views and disconnects their connected affiliates. Single-brand constraint means each affiliate has exactly one brand.
class BrandAffiliateController extends ApiController
{
    use ResolveCurrentProfessional;

    public function index(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can view affiliates.', 403);
        }

        $brandId = $professional->id;

        $links = BrandPartnerLink::query()
            ->where('brand_professional_id', $brandId)
            ->orderByDesc('updated_at')
            ->get(['affiliate_professional_id', 'slot', 'custom_photos_enabled', 'updated_at']);

        $affiliateIds = $links
            ->pluck('affiliate_professional_id')
            ->unique()
            ->values()
            ->all();

        $sitesByProfessionalId = Site::query()
            ->with(['professional'])
            ->whereIn('professional_id', $affiliateIds)
            ->whereHas('professional', function ($query): void {
                $query
                    ->where('status', 'active')
                    ->where('professional_type', '!=', 'brand');
            })
            ->get()
            ->keyBy('professional_id');

        $affiliates = $links
            ->map(function (BrandPartnerLink $link) use ($sitesByProfessionalId): ?array {
                /** @var Site|null $site */
                $site = $sitesByProfessionalId->get($link->affiliate_professional_id);
                if (! $site) {
                    return null;
                }

                $connectedProfessional = $site->professional;
                $name = trim(implode(' ', array_filter([
                    $connectedProfessional?->first_name,
                    $connectedProfessional?->last_name,
                ])));

                return [
                    'id' => $connectedProfessional?->id,
                    'full_name' => $name !== '' ? $name : ($connectedProfessional?->display_name ?? $connectedProfessional?->handle ?? 'Unknown'),
                    'display_name' => $connectedProfessional?->display_name,
                    'handle' => $connectedProfessional?->handle,
                    'professional_type' => $connectedProfessional?->professional_type,
                    'email' => $connectedProfessional?->primary_email ?? $connectedProfessional?->public_contact_email,
                    'phone' => $connectedProfessional?->phone ?? $connectedProfessional?->public_contact_number,
                    'connected_at' => optional($link->updated_at)->toIso8601String(),
                    'is_primary' => (int) $link->slot === BrandPartnerLinkService::PRIMARY_SLOT,
                    'custom_photos_enabled' => $link->custom_photos_enabled,
                ];
            })
            ->filter(fn (?array $affiliate): bool => is_array($affiliate) && filled($affiliate['id']))
            ->values()
            ->all();

        return $this->success([
            'affiliates' => $affiliates,
        ]);
    }

    public function disconnect(
        Request $request,
        string $affiliateId,
        BrandPartnerLinkLifecycleService $lifecycle,
    ): JsonResponse {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can disconnect affiliates.', 403);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $affiliate = Professional::query()->whereKey($affiliateId)->first();
        if (! $affiliate) {
            return $this->error('Affiliate not found.', 404);
        }

        $result = $lifecycle->disconnect(DisconnectRequest::forBrand(
            brand: $professional,
            affiliate: $affiliate,
            reason: $data['reason'] ?? null,
        ));

        if (! $result->disconnected) {
            return $this->error('Affiliate not found for this brand.', 404);
        }

        return $this->success([
            'disconnected' => true,
            'affiliate_id' => $affiliateId,
            'selections_removed' => $result->selectionsRemoved,
        ]);
    }

    /**
     * Per-affiliate snapshot for the brand affiliate-detail modal.
     *
     * One round-trip replaces the modal's previous "try one analytics
     * endpoint, then fall back to a list endpoint" dance. Aggregates are
     * lifetime-to-date — the modal isn't a date-range explorer.
     *
     * @return JsonResponse{ data: { affiliate_id, identity, totals, commission, page_views, recent_payouts, currency_code } }
     */
    public function snapshot(Request $request, string $affiliateId): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can view affiliate snapshots.', 403);
        }

        $brandId = (string) $professional->id;

        // Auth check — only return data for affiliates linked to this brand.
        $link = BrandPartnerLink::query()
            ->where('brand_professional_id', $brandId)
            ->where('affiliate_professional_id', $affiliateId)
            ->first();

        if (! $link) {
            return $this->error('Affiliate not found for this brand.', 404);
        }

        $affiliate = Professional::query()->whereKey($affiliateId)->first();
        if (! $affiliate) {
            return $this->error('Affiliate not found.', 404);
        }

        // Lifetime commerce aggregates from analytics.brand_affiliate_daily.
        // Pick the dominant currency by row count so multi-currency
        // affiliates resolve to their primary corridor for KPI display.
        $rows = DB::table('analytics.brand_affiliate_daily')
            ->where('brand_professional_id', $brandId)
            ->where('affiliate_professional_id', $affiliateId)
            ->get();

        $currencyCode = $rows->countBy('currency_code')->sortDesc()->keys()->first() ?? 'AUD';
        $primary = $rows->filter(fn ($r) => $r->currency_code === $currencyCode);

        $totals = [
            'orders_count' => (int) $primary->sum('orders_count'),
            'gross_cents' => (int) $primary->sum('gross_cents'),
            'net_cents' => (int) $primary->sum('net_cents'),
            'commission_net_cents' => (int) $primary->sum('commission_net_cents'),
            'customers_count' => (int) $primary->sum('customers_count'),
        ];

        // Page views — joined from site_metrics_daily so the modal can show
        // visits + unique visitors + a derived conversion rate. Lifetime so
        // the figure stays comparable to the totals block.
        $views = DB::table('analytics.site_metrics_daily')
            ->where('professional_id', $affiliateId)
            ->selectRaw('SUM(visits_count)::int as visits_count, SUM(unique_visitors)::int as unique_visitors')
            ->first();

        $visits = (int) ($views->visits_count ?? 0);
        $uniqueVisitors = (int) ($views->unique_visitors ?? 0);
        $conversionRate = $uniqueVisitors > 0
            ? round(($totals['orders_count'] / $uniqueVisitors) * 100, 2)
            : 0.0;

        // Commission state buckets — pending = unpaid + in-flight,
        // paid = completed, voided = cancelled-by-grace + failed. The
        // status pill in the modal reads these to surface "amount owed
        // / amount paid / amount lost" at a glance.
        $payouts = CommissionPayout::query()
            ->where('brand_professional_id', $brandId)
            ->where('affiliate_professional_id', $affiliateId)
            ->get();

        $bucketByStatus = $payouts->groupBy('status');
        $sumNet = fn (string $status): int => (int) ($bucketByStatus->get($status)?->sum('net_payout_cents') ?? 0);

        $commission = [
            'pending_cents' => $sumNet('pending') + $sumNet('pending_funds')
                + $sumNet('collecting') + $sumNet('collected') + $sumNet('transferring'),
            'paid_cents' => $sumNet('completed'),
            'voided_cents' => $sumNet('cancelled') + $sumNet('failed'),
        ];

        // Last 5 payouts so the modal can show a recent-history strip
        // without paginating. Ordered by processed_at desc (falls back to
        // created_at when not yet processed).
        $recentPayouts = $payouts
            ->sortByDesc(fn (CommissionPayout $p) => optional($p->processed_at ?? $p->created_at)->getTimestamp() ?? 0)
            ->take(5)
            ->map(fn (CommissionPayout $p) => [
                'id' => $p->id,
                'status' => $p->status,
                'net_payout_cents' => (int) $p->net_payout_cents,
                'currency_code' => strtoupper((string) ($p->currency_code ?? $currencyCode)),
                'processed_at' => optional($p->processed_at)->toIso8601String(),
                'created_at' => optional($p->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        $identityName = trim(implode(' ', array_filter([$affiliate->first_name, $affiliate->last_name])));

        return $this->success([
            'affiliate_id' => $affiliateId,
            'identity' => [
                'full_name' => $identityName !== '' ? $identityName : ($affiliate->display_name ?? $affiliate->handle ?? 'Unknown'),
                'display_name' => $affiliate->display_name,
                'handle' => $affiliate->handle,
                'professional_type' => $affiliate->professional_type,
            ],
            'totals' => $totals,
            'commission' => $commission,
            'page_views' => [
                'visits_count' => $visits,
                'unique_visitors' => $uniqueVisitors,
                'conversion_rate_percent' => $conversionRate,
            ],
            'recent_payouts' => $recentPayouts,
            'currency_code' => strtoupper((string) $currencyCode),
        ]);
    }

    public function updateCustomPhotos(Request $request, string $affiliateId): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can manage affiliate photo permissions.', 403);
        }

        $request->validate([
            'enabled' => ['present', 'nullable', 'boolean'],
        ]);

        $link = BrandPartnerLink::query()
            ->where('brand_professional_id', $professional->id)
            ->where('affiliate_professional_id', $affiliateId)
            ->first();

        if (! $link) {
            return $this->error('Affiliate not found for this brand.', 404);
        }

        $link->custom_photos_enabled = $request->input('enabled');
        $link->save();

        return $this->success([
            'affiliate_id' => $affiliateId,
            'custom_photos_enabled' => $link->custom_photos_enabled,
        ]);
    }
}
