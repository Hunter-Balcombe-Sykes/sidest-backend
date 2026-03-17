<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Support\Str;
use RuntimeException;

class BrandAffiliateInviteService
{
    public function createInvite(Professional $brand, array $attributes): BrandAffiliateInvite
    {
        $invite = new BrandAffiliateInvite([
            'brand_professional_id' => $brand->id,
            'token' => $this->generateUniqueToken(),
            'status' => 'pending',
            'invite_type' => $this->determineInviteType($attributes),
            'email' => $attributes['email'] ?? null,
            'email_lc' => isset($attributes['email']) ? mb_strtolower(trim((string) $attributes['email'])) : null,
            'phone' => $attributes['phone'] ?? null,
            'first_name' => $attributes['first_name'] ?? null,
            'last_name' => $attributes['last_name'] ?? null,
            'message' => $attributes['message'] ?? null,
        ]);

        $invite->save();

        return $invite->fresh(['brandProfessional']);
    }

    public function findByToken(string $token): ?BrandAffiliateInvite
    {
        return BrandAffiliateInvite::query()
            ->with(['brandProfessional'])
            ->where('token', trim($token))
            ->first();
    }

    public function claimInvite(BrandAffiliateInvite $invite, Professional $professional): BrandAffiliateInvite
    {
        if (mb_strtolower(trim((string) $professional->professional_type)) === 'brand') {
            throw new RuntimeException('Brand accounts cannot claim affiliate invites.');
        }

        if ($invite->status === 'accepted') {
            if ($invite->claimed_professional_id === $professional->id) {
                return $invite;
            }

            throw new RuntimeException('This invite has already been used.');
        }

        if ($invite->status !== 'pending') {
            throw new RuntimeException('This invite is no longer available.');
        }

        if ($invite->expires_at && $invite->expires_at->isPast()) {
            throw new RuntimeException('This invite has expired.');
        }

        $this->assertInviteMatchesProfessional($invite, $professional);

        $site = Site::query()->where('professional_id', $professional->id)->first();
        if (! $site) {
            throw new RuntimeException('Your site could not be found. Please complete account setup first.');
        }

        $settings = is_array($site->settings ?? null) ? $site->settings : [];
        $settings['brand_partner'] = [
            ...(is_array($settings['brand_partner'] ?? null) ? $settings['brand_partner'] : []),
            'professional_id' => $invite->brand_professional_id,
        ];
        $site->settings = $settings;
        $site->save();

        $invite->status = 'accepted';
        $invite->claimed_professional_id = $professional->id;
        $invite->accepted_at = now();
        $invite->save();

        return $invite->fresh(['brandProfessional', 'claimedProfessional']);
    }

    private function determineInviteType(array $attributes): string
    {
        $hasPersonalisation =
            filled($attributes['email'] ?? null) ||
            filled($attributes['phone'] ?? null) ||
            filled($attributes['first_name'] ?? null) ||
            filled($attributes['last_name'] ?? null);

        return $hasPersonalisation ? 'personalised' : 'generic';
    }

    private function generateUniqueToken(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $token = Str::random(48);
            $exists = BrandAffiliateInvite::query()->where('token', $token)->exists();
            if (! $exists) {
                return $token;
            }
        }

        throw new RuntimeException('Unable to generate a unique invite token.');
    }

    private function assertInviteMatchesProfessional(BrandAffiliateInvite $invite, Professional $professional): void
    {
        $inviteEmail = mb_strtolower(trim((string) ($invite->email ?? '')));
        $professionalEmail = mb_strtolower(trim((string) ($professional->primary_email ?? '')));
        if ($inviteEmail !== '' && $professionalEmail !== '' && $inviteEmail !== $professionalEmail) {
            throw new RuntimeException('This invite was issued for a different email address.');
        }

        $invitePhone = $this->normalizePhone($invite->phone);
        $professionalPhone = $this->normalizePhone($professional->phone ?? $professional->public_contact_number);
        if ($invitePhone !== '' && $professionalPhone !== '' && $invitePhone !== $professionalPhone) {
            throw new RuntimeException('This invite was issued for a different phone number.');
        }
    }

    private function normalizePhone(mixed $value): string
    {
        $stringValue = is_string($value) ? trim($value) : '';
        if ($stringValue === '') {
            return '';
        }

        return preg_replace('/\D+/u', '', $stringValue) ?? '';
    }
}
