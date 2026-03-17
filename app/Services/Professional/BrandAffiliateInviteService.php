<?php

namespace App\Services\Professional;

use App\Models\Core\Notifications\Notification;
use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use RuntimeException;

class BrandAffiliateInviteService
{
    public function checkRecipientAvailability(Professional $brand, ?string $email, ?string $phone): array
    {
        $normalizedEmail = $this->normalizeEmail($email);

        $emailExistsAsUser = false;
        $emailAlreadyConnectedToBrand = false;
        $emailExistsAsInvite = false;
        if ($normalizedEmail !== null) {
            $matchingEmailProfessionalIds = Professional::withTrashed()
                ->where(function ($query) use ($normalizedEmail) {
                    $query->whereRaw('LOWER(primary_email) = ?', [$normalizedEmail])
                        ->orWhereRaw('LOWER(public_contact_email) = ?', [$normalizedEmail]);
                })
                ->pluck('id');

            $emailExistsAsUser = $matchingEmailProfessionalIds->isNotEmpty();
            $emailAlreadyConnectedToBrand = $emailExistsAsUser
                && $this->brandHasConnectedProfessionals($brand, $matchingEmailProfessionalIds->all());

            $emailExistsAsInvite = BrandAffiliateInvite::query()
                ->where('status', 'pending')
                ->where('email_lc', $normalizedEmail)
                ->exists();
        }

        return [
            'email' => [
                'available' => !($emailExistsAsInvite || $emailAlreadyConnectedToBrand),
                'exists' => $emailExistsAsUser || $emailExistsAsInvite || $emailAlreadyConnectedToBrand,
                'existing_user' => $emailExistsAsUser,
                'already_connected_to_brand' => $emailAlreadyConnectedToBrand,
                'existing_invitation' => $emailExistsAsInvite,
            ],
            'phone' => [
                'available' => true,
                'exists' => false,
                'existing_user' => false,
                'already_connected_to_brand' => false,
                'existing_invitation' => false,
            ],
        ];
    }

    public function createInvite(Professional $brand, array $attributes): BrandAffiliateInvite
    {
        $this->assertRecipientAvailability(
            $brand,
            $attributes['email'] ?? null,
            null,
        );

        $expiresAt = match($attributes['expiration'] ?? null) {
            '24h' => now()->addHours(24),
            '7d' => now()->addDays(7),
            '30d' => now()->addDays(30),
            default => null,
        };

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
            'expires_at' => $expiresAt,
        ]);

        $invite->save();
        $this->notifyExistingEmailRecipients($brand, $invite);

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
    public function declineInvite(BrandAffiliateInvite $invite, Professional $professional): BrandAffiliateInvite
    {
        if (mb_strtolower(trim((string) $professional->professional_type)) === 'brand') {
            throw new RuntimeException('Brand accounts cannot decline affiliate invites.');
        }

        if ($invite->status === 'accepted') {
            if ($invite->claimed_professional_id === $professional->id) {
                throw new RuntimeException('This invite has already been accepted.');
            }

            throw new RuntimeException('This invite has already been used.');
        }

        if ($invite->status === 'declined') {
            return $invite;
        }

        if ($invite->status !== 'pending') {
            throw new RuntimeException('This invite is no longer available.');
        }

        if ($invite->expires_at && $invite->expires_at->isPast()) {
            throw new RuntimeException('This invite has expired.');
        }

        $this->assertInviteMatchesProfessional($invite, $professional);

        $invite->status = 'declined';
        $invite->save();

        return $invite->fresh(['brandProfessional', 'claimedProfessional']);
    }

    private function determineInviteType(array $attributes): string
    {
        $hasPersonalisation =
            filled($attributes['email'] ?? null) ||
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

    private function assertRecipientAvailability(Professional $brand, ?string $email, ?string $phone): void
    {
        $availability = $this->checkRecipientAvailability($brand, $email, null);

        if (($availability['email']['available'] ?? true) === false) {
            throw new RuntimeException('Email already has a pending invitation or is already connected to this brand.');
        }
    }

    private function assertInviteMatchesProfessional(BrandAffiliateInvite $invite, Professional $professional): void
    {
        $inviteEmail = mb_strtolower(trim((string) ($invite->email ?? '')));
        $professionalEmail = mb_strtolower(trim((string) ($professional->primary_email ?? '')));
        if ($inviteEmail !== '' && $professionalEmail !== '' && $inviteEmail !== $professionalEmail) {
            throw new RuntimeException('This invite was issued for a different email address.');
        }
    }

    private function normalizeEmail(?string $value): ?string
    {
        $stringValue = is_string($value) ? trim($value) : '';
        if ($stringValue === '') {
            return null;
        }

        return mb_strtolower($stringValue);
    }

    private function brandHasConnectedProfessionals(Professional $brand, array $professionalIds): bool
    {
        if ($professionalIds === []) {
            return false;
        }

        return Site::query()
            ->whereIn('professional_id', $professionalIds)
            ->where(function (Builder $query) use ($brand): void {
                $query
                    ->whereRaw("(settings->'brand_partner'->>'professional_id') = ?", [$brand->id])
                    ->orWhereRaw("(settings->'brandPartner'->>'professionalId') = ?", [$brand->id]);
            })
            ->exists();
    }

    private function notifyExistingEmailRecipients(Professional $brand, BrandAffiliateInvite $invite): void
    {
        if (!is_string($invite->email) || trim($invite->email) === '') {
            return;
        }

        $normalizedEmail = $this->normalizeEmail($invite->email);
        if ($normalizedEmail === null) {
            return;
        }

        $brandName = trim((string) ($brand->display_name ?: $brand->handle ?: 'this brand'));

        Professional::query()
            ->where('status', 'active')
            ->where(function ($query) use ($normalizedEmail) {
                $query->whereRaw('LOWER(primary_email) = ?', [$normalizedEmail])
                    ->orWhereRaw('LOWER(public_contact_email) = ?', [$normalizedEmail]);
            })
            ->get()
            ->each(function (Professional $professional) use ($invite, $brandName): void {
                Notification::query()->create([
                    'professional_id' => $professional->id,
                    'type' => 'Invitation',
                    'title' => 'New brand partner invitation!',
                    'body' => "You have a new invite to become a brand partner of {$brandName}",
                    'cta_url' => "/brand-affiliate-invites/{$invite->token}/claim",
                    'primary_action_label' => 'Accept Invitation',
                    'secondary_action_label' => 'Decline Invitation',
                    'secondary_action_url' => "/brand-affiliate-invites/{$invite->token}/decline",
                    'severity' => Notification::severityForFrontendType('Invitation'),
                    'starts_at' => now(),
                    'ends_at' => null,
                ]);
            });
    }
}
