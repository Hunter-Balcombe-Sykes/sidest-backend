<?php

namespace App\Policies;

use App\Models\Commerce\BrandAffiliateRollup;
use App\Models\Core\Professional\Professional;
use App\Services\Store\BrandAccessService;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for commission financial records.
 *
 * Covers: CommissionPayout, CommissionMovement, BrandCommissionTopup.
 *
 * Read access:
 *   - The affiliate (CommissionPayout/LedgerEntry where affiliate_professional_id matches)
 *   - The brand owner
 *   - Brand team members with canReadBrandFinancialAnalytics capability
 *
 * Write access:
 *   - Brand owner or team member with canManageBrand capability
 *   - NOT affiliates (read-only for them)
 *
 * BrandCommissionTopup has no affiliate_professional_id; affiliate check is skipped.
 */
class CommissionPolicy extends BasePolicy
{
    public function __construct(private readonly BrandAccessService $brandAccess) {}

    public function view(Professional $actor, Model $record): bool|Response
    {
        $brandId = (string) ($record->brand_professional_id ?? '');
        $affiliateId = (string) ($record->affiliate_professional_id ?? '');
        $actorId = (string) $actor->id;

        if ($brandId === '') {
            return $this->denyAsNotFound();
        }

        // Affiliate can view their own payout/ledger record
        if ($affiliateId !== '' && $actorId === $affiliateId) {
            return true;
        }

        // Brand owner can always view
        if ($actorId === $brandId) {
            return true;
        }

        // Brand team member with financial read capability
        if ($this->brandAccess->canReadBrandFinancialAnalytics($actor, $brandId)) {
            return true;
        }

        return $this->denyAsNotFound();
    }

    /**
     * Authorizes a professional to view their own affiliate projections.
     *
     * The skeleton must carry the affiliate_professional_id of the *requested* projections.
     * Defense-in-depth: RLS on commerce.brand_affiliate_rollup also blocks cross-tenant reads,
     * but we want a clean 403 at the HTTP layer rather than an empty result.
     */
    public function viewProjections(Professional $pro, BrandAffiliateRollup $skeleton): bool
    {
        return (string) $pro->id === (string) $skeleton->affiliate_professional_id;
    }

    public function update(Professional $actor, Model $record): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        $brandId = (string) ($record->brand_professional_id ?? '');
        $actorId = (string) $actor->id;

        if ($brandId === '') {
            return $this->denyAsNotFound();
        }

        if ($actorId === $brandId) {
            return true;
        }

        if ($this->brandAccess->canManageBrand($actor, $brandId)) {
            return true;
        }

        return $this->denyAsNotFound();
    }

    public function delete(Professional $actor, Model $record): bool|Response
    {
        return $this->update($actor, $record);
    }

    /**
     * Brand can only top up their own wallet, not another professional's.
     */
    public function topUp(Professional $actor, Professional $brand): bool
    {
        return $actor->id === $brand->id
            && ($actor->professional_type ?? null) === 'brand';
    }

    /**
     * Brand can only manage their own payment method, not another professional's.
     */
    public function managePaymentMethod(Professional $actor, Professional $brand): bool
    {
        return $actor->id === $brand->id
            && ($actor->professional_type ?? null) === 'brand';
    }

    /**
     * Wallet management follows the same rules as topping up.
     */
    public function manageWallet(Professional $actor, Professional $brand): bool
    {
        return $this->topUp($actor, $brand);
    }
}
