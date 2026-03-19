<?php

namespace App\Services\Store;

use App\Models\Core\Enterprise\Enterprise;
use App\Models\Core\Enterprise\EnterpriseBrandLink;
use App\Models\Core\Enterprise\ProfessionalEnterpriseMembership;
use App\Models\Core\Professional\Professional;

class BrandAccessService
{
    /**
     * @return array<int, string>
     */
    public function managedBrandIds(Professional $professional): array
    {
        $brandIds = [];

        if ($this->isBrandProfessional($professional)) {
            $brandIds[] = (string) $professional->id;
        }

        $enterpriseIds = ProfessionalEnterpriseMembership::query()
            ->where('professional_id', $professional->id)
            ->whereNull('ends_at')
            ->whereIn('relationship_type', ['owner', 'employee'])
            ->pluck('enterprise_id')
            ->filter(fn ($id): bool => is_string($id) && trim($id) !== '')
            ->values()
            ->all();

        if ($enterpriseIds !== []) {
            $distributorEnterpriseIds = Enterprise::query()
                ->whereIn('id', $enterpriseIds)
                ->where('enterprise_type', 'distributor')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->pluck('id')
                ->filter(fn ($id): bool => is_string($id) && trim($id) !== '')
                ->values()
                ->all();

            if ($distributorEnterpriseIds !== []) {
                $enterpriseBrandIds = EnterpriseBrandLink::query()
                    ->whereIn('enterprise_id', $distributorEnterpriseIds)
                    ->where('status', 'active')
                    ->pluck('brand_professional_id')
                    ->filter(fn ($id): bool => is_string($id) && trim($id) !== '')
                    ->values()
                    ->all();

                $brandIds = [...$brandIds, ...$enterpriseBrandIds];
            }
        }

        $brandIds = array_values(array_unique(array_filter($brandIds, fn ($id): bool => is_string($id) && $id !== '')));

        return $brandIds;
    }

    public function canManageBrand(Professional $professional, string $brandProfessionalId): bool
    {
        $brandProfessionalId = trim($brandProfessionalId);

        if ($brandProfessionalId === '') {
            return false;
        }

        return in_array($brandProfessionalId, $this->managedBrandIds($professional), true);
    }

    public function isBrandProfessional(Professional $professional): bool
    {
        return strtolower(trim((string) ($professional->professional_type ?? ''))) === 'brand';
    }
}
