<?php

namespace App\Services\Store;

use App\Models\Core\Enterprise\Enterprise;
use App\Models\Core\Enterprise\EnterpriseBrandLink;
use App\Models\Core\Enterprise\ProfessionalEnterpriseMembership;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\BrandTeamMembership;

class BrandAccessService
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_FINANCE = 'finance';
    public const ROLE_MARKETING = 'marketing';
    public const ROLE_ANALYST = 'analyst';
    public const ROLE_READ_ONLY = 'read_only';
    public const ROLE_ENTERPRISE_FULL = 'enterprise_full';

    public const CAPABILITY_ANALYTICS_NON_FINANCIAL_READ = 'analytics.non_financial.read';
    public const CAPABILITY_ANALYTICS_FINANCIAL_READ = 'analytics.financial.read';
    public const CAPABILITY_EXPORT_NON_FINANCIAL = 'export.non_financial';
    public const CAPABILITY_EXPORT_FINANCIAL = 'export.financial';
    public const CAPABILITY_STORE_MANAGE = 'store.manage';
    public const CAPABILITY_SHOPIFY_MANAGE = 'shopify.manage';

    /**
     * @var array<string, array<string, array{roles: array<int, string>, capabilities: array<int, string>}>>
     */
    private array $resolvedBrandAccessCache = [];

    /**
     * @return array<int, string>
     */
    public function managedBrandIds(Professional $professional): array
    {
        return $this->brandIdsForCapability($professional, self::CAPABILITY_STORE_MANAGE);
    }

    public function canManageBrand(Professional $professional, string $brandProfessionalId): bool
    {
        return $this->can($professional, $brandProfessionalId, self::CAPABILITY_STORE_MANAGE);
    }

    public function canManageShopify(Professional $professional, string $brandProfessionalId): bool
    {
        return $this->can($professional, $brandProfessionalId, self::CAPABILITY_SHOPIFY_MANAGE);
    }

    public function canReadBrandAnalytics(Professional $professional, string $brandProfessionalId): bool
    {
        return $this->can($professional, $brandProfessionalId, self::CAPABILITY_ANALYTICS_NON_FINANCIAL_READ);
    }

    public function canReadBrandFinancialAnalytics(Professional $professional, string $brandProfessionalId): bool
    {
        return $this->can($professional, $brandProfessionalId, self::CAPABILITY_ANALYTICS_FINANCIAL_READ);
    }

    /**
     * @return array<int, string>
     */
    public function brandIdsForCapability(Professional $professional, string $capability): array
    {
        $capability = mb_strtolower(trim($capability));
        if ($capability === '') {
            return [];
        }

        $brandIds = [];
        foreach ($this->resolvedBrandAccess($professional) as $brandId => $access) {
            if (in_array($capability, $access['capabilities'], true)) {
                $brandIds[] = (string) $brandId;
            }
        }

        return array_values(array_unique(array_filter($brandIds, static fn ($id): bool => is_string($id) && $id !== '')));
    }

    public function can(Professional $professional, string $brandProfessionalId, string $capability): bool
    {
        $brandProfessionalId = trim($brandProfessionalId);
        if ($brandProfessionalId === '') {
            return false;
        }

        $capability = mb_strtolower(trim($capability));
        if ($capability === '') {
            return false;
        }

        $access = $this->resolvedBrandAccess($professional);
        if (! isset($access[$brandProfessionalId])) {
            return false;
        }

        return in_array($capability, $access[$brandProfessionalId]['capabilities'], true);
    }

    public function isBrandProfessional(Professional $professional): bool
    {
        return strtolower(trim((string) ($professional->professional_type ?? ''))) === 'brand';
    }

    /**
     * @return array<string, array{roles: array<int, string>, capabilities: array<int, string>}>
     */
    private function resolvedBrandAccess(Professional $professional): array
    {
        $cacheKey = (string) $professional->id;
        if (isset($this->resolvedBrandAccessCache[$cacheKey])) {
            return $this->resolvedBrandAccessCache[$cacheKey];
        }

        $rolesByBrand = [];

        if ($this->isBrandProfessional($professional)) {
            $this->grantRole($rolesByBrand, (string) $professional->id, self::ROLE_OWNER);
        }

        $enterpriseIds = ProfessionalEnterpriseMembership::query()
            ->where('professional_id', $professional->id)
            ->whereNull('ends_at')
            ->whereIn('relationship_type', ['owner', 'employee'])
            ->pluck('enterprise_id')
            ->filter(static fn ($id): bool => is_string($id) && trim($id) !== '')
            ->values()
            ->all();

        if ($enterpriseIds !== []) {
            $distributorEnterpriseIds = Enterprise::query()
                ->whereIn('id', $enterpriseIds)
                ->where('enterprise_type', 'distributor')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->pluck('id')
                ->filter(static fn ($id): bool => is_string($id) && trim($id) !== '')
                ->values()
                ->all();

            if ($distributorEnterpriseIds !== []) {
                $enterpriseBrandIds = EnterpriseBrandLink::query()
                    ->whereIn('enterprise_id', $distributorEnterpriseIds)
                    ->where('status', 'active')
                    ->pluck('brand_professional_id')
                    ->filter(static fn ($id): bool => is_string($id) && trim($id) !== '')
                    ->values()
                    ->all();

                foreach ($enterpriseBrandIds as $enterpriseBrandId) {
                    $this->grantRole($rolesByBrand, (string) $enterpriseBrandId, self::ROLE_ENTERPRISE_FULL);
                }
            }
        }

        $teamMemberships = BrandTeamMembership::query()
            ->where('member_professional_id', $professional->id)
            ->where('status', 'active')
            ->get(['brand_professional_id', 'role']);

        foreach ($teamMemberships as $membership) {
            $brandId = trim((string) $membership->brand_professional_id);
            $role = mb_strtolower(trim((string) $membership->role));

            if ($brandId === '' || ! in_array($role, $this->teamRoles(), true)) {
                continue;
            }

            $this->grantRole($rolesByBrand, $brandId, $role);
        }

        $resolved = [];
        foreach ($rolesByBrand as $brandId => $roles) {
            $capabilities = [];
            foreach ($roles as $role) {
                $capabilities = [...$capabilities, ...$this->capabilitiesForRole($role)];
            }

            $resolved[(string) $brandId] = [
                'roles' => array_values(array_unique(array_map('strval', $roles))),
                'capabilities' => array_values(array_unique(array_filter(array_map('strval', $capabilities)))),
            ];
        }

        $this->resolvedBrandAccessCache[$cacheKey] = $resolved;

        return $resolved;
    }

    /**
     * @param  array<string, array<int, string>>  $rolesByBrand
     */
    private function grantRole(array &$rolesByBrand, string $brandProfessionalId, string $role): void
    {
        $brandProfessionalId = trim($brandProfessionalId);
        $role = mb_strtolower(trim($role));

        if ($brandProfessionalId === '' || $role === '') {
            return;
        }

        if (! isset($rolesByBrand[$brandProfessionalId])) {
            $rolesByBrand[$brandProfessionalId] = [];
        }

        $rolesByBrand[$brandProfessionalId][] = $role;
    }

    /**
     * @return array<int, string>
     */
    private function teamRoles(): array
    {
        return [
            self::ROLE_OWNER,
            self::ROLE_FINANCE,
            self::ROLE_MARKETING,
            self::ROLE_ANALYST,
            self::ROLE_READ_ONLY,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function capabilitiesForRole(string $role): array
    {
        $role = mb_strtolower(trim($role));

        return match ($role) {
            self::ROLE_OWNER,
            self::ROLE_FINANCE,
            self::ROLE_ENTERPRISE_FULL => [
                self::CAPABILITY_ANALYTICS_NON_FINANCIAL_READ,
                self::CAPABILITY_ANALYTICS_FINANCIAL_READ,
                self::CAPABILITY_EXPORT_NON_FINANCIAL,
                self::CAPABILITY_EXPORT_FINANCIAL,
                self::CAPABILITY_STORE_MANAGE,
                self::CAPABILITY_SHOPIFY_MANAGE,
            ],
            self::ROLE_MARKETING => [
                self::CAPABILITY_ANALYTICS_NON_FINANCIAL_READ,
                self::CAPABILITY_EXPORT_NON_FINANCIAL,
                self::CAPABILITY_STORE_MANAGE,
            ],
            self::ROLE_ANALYST,
            self::ROLE_READ_ONLY => [
                self::CAPABILITY_ANALYTICS_NON_FINANCIAL_READ,
                self::CAPABILITY_ANALYTICS_FINANCIAL_READ,
                self::CAPABILITY_EXPORT_NON_FINANCIAL,
                self::CAPABILITY_EXPORT_FINANCIAL,
            ],
            default => [],
        };
    }
}
