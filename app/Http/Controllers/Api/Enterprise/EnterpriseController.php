<?php

namespace App\Http\Controllers\Api\Enterprise;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Enterprise\CreateEnterpriseRequest;
use App\Http\Requests\Api\Enterprise\UpdateEnterpriseRequest;
use App\Models\Core\Enterprise\Enterprise;
use App\Models\Core\Enterprise\ProfessionalEnterpriseMembership;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnterpriseController extends ApiController
{
    /**
     * GET /enterprise/me
     */
    public function show(Request $request)
    {
        $uid = $this->supabaseUid($request);
        if ($uid === null) {
            return $this->error('Unauthenticated', 401);
        }

        $enterprise = $this->findEnterpriseByUid($uid);
        if (! $enterprise) {
            return $this->error('Enterprise account not found.', 404);
        }

        return $this->success([
            'enterprise' => $enterprise,
        ]);
    }

    /**
     * POST /enterprise/me
     */
    public function store(CreateEnterpriseRequest $request)
    {
        $uid = $this->supabaseUid($request);
        if ($uid === null) {
            return $this->error('Unauthenticated', 401);
        }

        if ($this->findEnterpriseByUid($uid)) {
            return $this->error('Enterprise account already exists for this user.', 409);
        }

        $validated = $request->validated();
        $professional = Professional::query()
            ->where('auth_user_id', $uid)
            ->whereNull('deleted_at')
            ->first();

        if (! $professional) {
            return $this->error(
                'Professional profile is required before creating an enterprise account. Call /api/bootstrap first.',
                422
            );
        }

        $enterprise = DB::transaction(function () use ($validated, $uid, $professional) {
            $enterprise = new Enterprise([
                ...$validated,
                'auth_user_id' => $uid,
                'status' => $validated['status'] ?? 'active',
                // Default business contact details from linked professional unless explicitly provided.
                'primary_email' => $validated['primary_email'] ?? $professional?->primary_email,
                'phone' => $validated['phone'] ?? $professional?->phone,
                'public_contact_email' => $validated['public_contact_email'] ?? $professional?->public_contact_email,
                'public_contact_number' => $validated['public_contact_number'] ?? $professional?->public_contact_number,
                'country_code' => $validated['country_code'] ?? $professional?->country_code,
                'timezone' => $validated['timezone'] ?? $professional?->timezone,
                'location_street_address' => $validated['location_street_address'] ?? $professional?->location_street_address,
                'location_city' => $validated['location_city'] ?? $professional?->location_city,
                'location_state' => $validated['location_state'] ?? $professional?->location_state,
                'location_postcode' => $validated['location_postcode'] ?? $professional?->location_postcode,
                'location_country' => $validated['location_country'] ?? $professional?->location_country,
            ]);
            $enterprise->save();

            $existingMembership = ProfessionalEnterpriseMembership::query()
                ->where('professional_id', $professional->id)
                ->where('enterprise_id', $enterprise->id)
                ->whereNull('ends_at')
                ->first();

            if (! $existingMembership) {
                $hasActivePrimary = ProfessionalEnterpriseMembership::query()
                    ->where('professional_id', $professional->id)
                    ->where('is_primary', true)
                    ->whereNull('ends_at')
                    ->exists();

                ProfessionalEnterpriseMembership::create([
                    'professional_id' => $professional->id,
                    'enterprise_id' => $enterprise->id,
                    'relationship_type' => 'owner',
                    'is_primary' => ! $hasActivePrimary,
                    'starts_at' => now(),
                    'metadata' => ['source' => 'enterprise_self_signup'],
                ]);
            }

            if (empty($professional->primary_enterprise_id)) {
                $professional->primary_enterprise_id = $enterprise->id;
                $professional->save();
            }

            return $enterprise;
        });

        return $this->success([
            'enterprise' => $enterprise->fresh(),
        ], 201);
    }

    /**
     * PATCH /enterprise/me
     */
    public function update(UpdateEnterpriseRequest $request)
    {
        $uid = $this->supabaseUid($request);
        if ($uid === null) {
            return $this->error('Unauthenticated', 401);
        }

        $enterprise = $this->findEnterpriseByUid($uid);
        if (! $enterprise) {
            return $this->error('Enterprise account not found.', 404);
        }

        $enterprise->fill($request->validated());
        $enterprise->save();

        return $this->success([
            'enterprise' => $enterprise->fresh(),
        ]);
    }

    /**
     * DELETE /enterprise/me
     */
    public function destroy(Request $request)
    {
        $uid = $this->supabaseUid($request);
        if ($uid === null) {
            return $this->error('Unauthenticated', 401);
        }

        $enterprise = $this->findEnterpriseByUid($uid);
        if (! $enterprise) {
            return $this->error('Enterprise account not found.', 404);
        }

        DB::transaction(function () use ($enterprise) {
            ProfessionalEnterpriseMembership::query()
                ->where('enterprise_id', $enterprise->id)
                ->whereNull('ends_at')
                ->update(['ends_at' => now()]);

            Professional::query()
                ->where('primary_enterprise_id', $enterprise->id)
                ->update(['primary_enterprise_id' => null]);

            $enterprise->status = 'inactive';
            $enterprise->deleted_at = now();
            $enterprise->save();
        });

        return $this->success([
            'deleted' => true,
        ]);
    }

    private function supabaseUid(Request $request): ?string
    {
        $uid = $request->attributes->get('supabase_uid');

        if (! is_string($uid) || trim($uid) === '') {
            return null;
        }

        return trim($uid);
    }

    private function findEnterpriseByUid(string $uid): ?Enterprise
    {
        return Enterprise::query()
            ->where('auth_user_id', $uid)
            ->whereNull('deleted_at')
            ->first();
    }
}
