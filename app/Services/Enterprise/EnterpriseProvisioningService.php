<?php

namespace App\Services\Enterprise;

use App\Models\Core\Enterprise\Enterprise;
use App\Models\Core\Enterprise\ProfessionalEnterpriseMembership;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;

class EnterpriseProvisioningService
{
    /**
     * Professional types that should auto-provision enterprise accounts.
     *
     * @var array<int, string>
     */
    private array $enterpriseProfessionalTypes = ['promoter', 'barbershop', 'salon'];

    public function isEnterpriseProfessionalType(?string $professionalType): bool
    {
        $type = is_string($professionalType) ? strtolower(trim($professionalType)) : '';

        return in_array($type, $this->enterpriseProfessionalTypes, true);
    }

    public function ensureForProfessional(Professional $professional, ?string $enterpriseType = null): Enterprise
    {
        $resolvedEnterpriseType = is_string($enterpriseType) && trim($enterpriseType) !== ''
            ? strtolower(trim($enterpriseType))
            : strtolower((string) $professional->professional_type);

        if (! $this->isEnterpriseProfessionalType($resolvedEnterpriseType)) {
            throw new \InvalidArgumentException('Invalid enterprise type for enterprise provisioning.');
        }

        return DB::transaction(function () use ($professional, $resolvedEnterpriseType) {
            $enterprise = null;

            $authUserId = is_string($professional->auth_user_id) ? trim($professional->auth_user_id) : '';
            if ($authUserId !== '') {
                $enterprise = Enterprise::query()
                    ->where('auth_user_id', $authUserId)
                    ->whereNull('deleted_at')
                    ->first();
            }

            if (! $enterprise) {
                $enterprise = Enterprise::query()
                    ->whereNull('deleted_at')
                    ->whereHas('memberships', function ($query) use ($professional): void {
                        $query
                            ->where('professional_id', $professional->id)
                            ->where('relationship_type', 'owner')
                            ->whereNull('ends_at');
                    })
                    ->first();
            }

            if (! $enterprise) {
                $enterprise = new Enterprise([
                    'auth_user_id' => $professional->auth_user_id,
                    'name' => $professional->display_name ?: $professional->handle,
                    'handle' => $professional->handle ?: null,
                    'enterprise_type' => $resolvedEnterpriseType,
                    'status' => 'active',
                    'primary_email' => $professional->primary_email,
                    'phone' => $professional->phone,
                    'public_contact_email' => $professional->public_contact_email,
                    'public_contact_number' => $professional->public_contact_number,
                    'country_code' => $professional->country_code,
                    'timezone' => $professional->timezone,
                    'location_street_address' => $professional->location_street_address,
                    'location_city' => $professional->location_city,
                    'location_state' => $professional->location_state,
                    'location_postcode' => $professional->location_postcode,
                    'location_country' => $professional->location_country,
                    'metadata' => [
                        'source' => 'professional_type_auto_provisioning',
                    ],
                ]);
                $enterprise->save();
            } else {
                $enterprise->enterprise_type = $resolvedEnterpriseType;
                $enterprise->status = $enterprise->status ?: 'active';
                $enterprise->name = $enterprise->name ?: ($professional->display_name ?: $professional->handle);
                $enterprise->handle = $enterprise->handle ?: ($professional->handle ?: null);
                $enterprise->primary_email = $enterprise->primary_email ?: $professional->primary_email;
                $enterprise->phone = $enterprise->phone ?: $professional->phone;
                $enterprise->public_contact_email = $enterprise->public_contact_email ?: $professional->public_contact_email;
                $enterprise->public_contact_number = $enterprise->public_contact_number ?: $professional->public_contact_number;
                $enterprise->country_code = $enterprise->country_code ?: $professional->country_code;
                $enterprise->timezone = $enterprise->timezone ?: $professional->timezone;
                $enterprise->location_street_address = $enterprise->location_street_address ?: $professional->location_street_address;
                $enterprise->location_city = $enterprise->location_city ?: $professional->location_city;
                $enterprise->location_state = $enterprise->location_state ?: $professional->location_state;
                $enterprise->location_postcode = $enterprise->location_postcode ?: $professional->location_postcode;
                $enterprise->location_country = $enterprise->location_country ?: $professional->location_country;
                $enterprise->save();
            }

            $membership = ProfessionalEnterpriseMembership::query()
                ->where('professional_id', $professional->id)
                ->where('enterprise_id', $enterprise->id)
                ->whereNull('ends_at')
                ->first();

            if (! $membership) {
                $hasActivePrimary = ProfessionalEnterpriseMembership::query()
                    ->where('professional_id', $professional->id)
                    ->where('is_primary', true)
                    ->whereNull('ends_at')
                    ->exists();

                $membership = ProfessionalEnterpriseMembership::create([
                    'professional_id' => $professional->id,
                    'enterprise_id' => $enterprise->id,
                    'relationship_type' => 'owner',
                    'is_primary' => ! $hasActivePrimary,
                    'starts_at' => now(),
                    'metadata' => ['source' => 'professional_type_auto_provisioning'],
                ]);
            }

            if ($professional->primary_enterprise_id !== $enterprise->id) {
                ProfessionalEnterpriseMembership::query()
                    ->where('professional_id', $professional->id)
                    ->where('is_primary', true)
                    ->whereNull('ends_at')
                    ->update(['is_primary' => false]);

                if ($membership && ! $membership->is_primary) {
                    $membership->is_primary = true;
                    $membership->save();
                }

                $professional->primary_enterprise_id = $enterprise->id;
                $professional->save();
            }

            return $enterprise;
        });
    }
}
