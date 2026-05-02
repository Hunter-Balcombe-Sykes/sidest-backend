<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Platform-wide stats for the staff ops dashboard. Single aggregation call — counts by professional_type, active subscriptions, pending commissions.
class StaffStatsController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $typeCounts = DB::table('core.professionals')
            ->whereNull('deleted_at')
            ->selectRaw('professional_type, count(*) as total')
            ->groupBy('professional_type')
            ->pluck('total', 'professional_type');

        $activeSubscriptions = DB::table('billing.subscriptions')
            ->whereNull('ended_at')
            ->count();

        $pendingCommissionCents = DB::table('commerce.commission_ledger_entries')
            ->where('status', 'pending')
            ->sum('amount_cents');

        $brands = (int) ($typeCounts->get('brand') ?? 0);
        $influencers = (int) ($typeCounts->get('influencer') ?? 0);
        $professionals = (int) ($typeCounts->get('professional') ?? 0);

        return $this->success([
            'professionals' => [
                'brands' => $brands,
                'influencers' => $influencers,
                'professionals' => $professionals,
                'total' => $brands + $influencers + $professionals,
            ],
            'subscriptions' => [
                'active_count' => (int) $activeSubscriptions,
            ],
            'commissions' => [
                'pending_cents' => (int) $pendingCommissionCents,
            ],
        ]);
    }
}
