<?php

namespace App\Services\Professional;

use App\Models\Core\Notifications\Notification;
use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class BrandAffiliateInviteService
{
    private const BULK_MAX_ROWS = 500;

    private const NON_ACCEPTED_STATUSES = ['pending', 'expired', 'declined'];

    public function __construct(
        private readonly BrandPartnerLinkService $brandPartnerLinks
    ) {}

    public function checkRecipientAvailability(Professional $brand, ?string $email, ?string $phone): array
    {
        $normalizedEmail = $this->normalizeEmail($email);

        $emailExistsAsUser = false;
        $emailAlreadyConnectedToBrand = false;
        $emailExistsAsInvite = false;
        $willRefresh = false;

        if ($normalizedEmail !== null) {
            $this->expirePendingInvitesByBrandEmail((string) $brand->id, $normalizedEmail);

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
                ->where('brand_professional_id', $brand->id)
                ->where('email_lc', $normalizedEmail)
                ->whereIn('status', self::NON_ACCEPTED_STATUSES)
                ->exists();

            $willRefresh = $emailExistsAsInvite && ! $emailAlreadyConnectedToBrand;
        }

        return [
            'email' => [
                'available' => ! $emailAlreadyConnectedToBrand,
                'exists' => $emailExistsAsUser || $emailExistsAsInvite || $emailAlreadyConnectedToBrand,
                'existing_user' => $emailExistsAsUser,
                'already_connected_to_brand' => $emailAlreadyConnectedToBrand,
                'existing_invitation' => $emailExistsAsInvite,
                'will_refresh' => $willRefresh,
            ],
            'phone' => [
                'available' => true,
                'exists' => false,
                'existing_user' => false,
                'already_connected_to_brand' => false,
                'existing_invitation' => false,
                'will_refresh' => false,
            ],
        ];
    }

    public function createInvite(Professional $brand, array $attributes): BrandAffiliateInvite
    {
        $result = $this->createOrRefreshInvite($brand, $attributes);

        return $result['invite'];
    }

    /**
     * @return array{invite: BrandAffiliateInvite, action: string}
     */
    public function createOrRefreshInvite(Professional $brand, array $attributes): array
    {
        $result = $this->upsertInvite($brand, $attributes);
        $this->notifyExistingEmailRecipientsBatch($brand, collect([$result['invite']]));

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{summary: array<string, int>, results: array<int, array<string, mixed>>}
     */
    public function processBulkInvites(Professional $brand, array $rows): array
    {
        $rows = array_values($rows);
        if ($rows === []) {
            throw new RuntimeException('No invite rows were provided.');
        }

        if (count($rows) > self::BULK_MAX_ROWS) {
            throw new RuntimeException('Bulk invite row limit exceeded. Maximum 500 rows are allowed per request.');
        }

        $lastRowByEmail = [];
        foreach ($rows as $index => $rawRow) {
            $attributes = $this->extractBulkInviteAttributes($rawRow);
            $normalizedEmail = $this->normalizeEmail($attributes['email'] ?? null);
            if ($normalizedEmail !== null) {
                $lastRowByEmail[$normalizedEmail] = $index;
            }
        }

        $results = [];
        $created = 0;
        $refreshed = 0;
        $skipped = 0;
        $errors = 0;
        $notifyInvites = [];

        foreach ($rows as $index => $rawRow) {
            $rowNumber = $this->extractBulkRowNumber($rawRow, $index + 1);
            $attributes = $this->extractBulkInviteAttributes($rawRow);
            $normalizedEmail = $this->normalizeEmail($attributes['email'] ?? null);

            if ($normalizedEmail !== null && ($lastRowByEmail[$normalizedEmail] ?? $index) !== $index) {
                $skipped++;
                $results[] = [
                    'row' => $rowNumber,
                    'email' => $attributes['email'] ?? null,
                    'status' => 'skipped',
                    'error_code' => 'skipped_duplicate_superseded',
                    'error_message' => 'Superseded by a later row for the same email.',
                ];
                continue;
            }

            $validator = Validator::make($attributes, $this->bulkInviteRules());
            if ($validator->fails()) {
                $errors++;
                $results[] = [
                    'row' => $rowNumber,
                    'email' => $attributes['email'] ?? null,
                    'status' => 'error',
                    'error_code' => 'validation_failed',
                    'error_message' => (string) $validator->errors()->first(),
                ];
                continue;
            }

            try {
                $outcome = $this->upsertInvite($brand, $validator->validated());
                /** @var BrandAffiliateInvite $invite */
                $invite = $outcome['invite'];
                $action = (string) ($outcome['action'] ?? 'created');

                if ($action === 'refreshed') {
                    $refreshed++;
                } else {
                    $created++;
                }

                $results[] = [
                    'row' => $rowNumber,
                    'email' => $invite->email,
                    'status' => $action,
                    'invite_id' => (string) $invite->id,
                    'token' => (string) $invite->token,
                ];

                $notifyInvites[] = $invite;
            } catch (RuntimeException $exception) {
                $errors++;
                $results[] = [
                    'row' => $rowNumber,
                    'email' => $attributes['email'] ?? null,
                    'status' => 'error',
                    'error_code' => $this->rowErrorCodeForException($exception),
                    'error_message' => $exception->getMessage(),
                ];
            }
        }

        $this->notifyExistingEmailRecipientsBatch(
            $brand,
            collect($notifyInvites)->filter(fn ($invite) => $invite instanceof BrandAffiliateInvite)->unique('id')->values()
        );

        return [
            'summary' => [
                'total_rows' => count($rows),
                'created_count' => $created,
                'refreshed_count' => $refreshed,
                'skipped_count' => $skipped,
                'error_count' => $errors,
            ],
            'results' => $results,
        ];
    }

    public function findByToken(string $token): ?BrandAffiliateInvite
    {
        $invite = BrandAffiliateInvite::query()
            ->with(['brandProfessional'])
            ->where('token', trim($token))
            ->first();

        if (! $invite) {
            return null;
        }

        return $this->expireInviteIfNeeded($invite)->fresh(['brandProfessional', 'claimedProfessional']);
    }

    public function claimInvite(BrandAffiliateInvite $invite, Professional $professional): BrandAffiliateInvite
    {
        return DB::transaction(function () use ($invite, $professional): BrandAffiliateInvite {
            if (mb_strtolower(trim((string) $professional->professional_type)) === 'brand') {
                throw new RuntimeException('Brand accounts cannot claim affiliate invites.');
            }

            $lockedInvite = BrandAffiliateInvite::query()
                ->whereKey($invite->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedInvite) {
                throw new RuntimeException('Invite not found.');
            }

            $lockedInvite = $this->expireInviteIfNeeded($lockedInvite);

            if ($lockedInvite->status === 'accepted') {
                if ($lockedInvite->claimed_professional_id === $professional->id) {
                    return $lockedInvite->fresh(['brandProfessional', 'claimedProfessional']);
                }

                throw new RuntimeException('This invite has already been used.');
            }

            if ($lockedInvite->status !== 'pending') {
                throw new RuntimeException('This invite is no longer available.');
            }

            if ($lockedInvite->expires_at && $lockedInvite->expires_at->isPast()) {
                throw new RuntimeException('This invite has expired.');
            }

            $this->assertInviteMatchesProfessional($lockedInvite, $professional);

            $site = Site::query()
                ->where('professional_id', $professional->id)
                ->first();
            if (! $site) {
                throw new RuntimeException('Your site could not be found. Please complete account setup first.');
            }

            $brandProfessionalId = (string) $lockedInvite->brand_professional_id;
            $this->brandPartnerLinks->connectBrandToAffiliate((string) $professional->id, $brandProfessionalId);

            $lockedInvite->status = 'accepted';
            $lockedInvite->claimed_professional_id = $professional->id;
            $lockedInvite->accepted_at = now();
            $lockedInvite->save();

            return $lockedInvite->fresh(['brandProfessional', 'claimedProfessional']);
        });
    }

    public function declineInvite(BrandAffiliateInvite $invite, Professional $professional): BrandAffiliateInvite
    {
        return DB::transaction(function () use ($invite, $professional): BrandAffiliateInvite {
            if (mb_strtolower(trim((string) $professional->professional_type)) === 'brand') {
                throw new RuntimeException('Brand accounts cannot decline affiliate invites.');
            }

            $lockedInvite = BrandAffiliateInvite::query()
                ->whereKey($invite->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedInvite) {
                throw new RuntimeException('Invite not found.');
            }

            $lockedInvite = $this->expireInviteIfNeeded($lockedInvite);

            if ($lockedInvite->status === 'accepted') {
                if ($lockedInvite->claimed_professional_id === $professional->id) {
                    throw new RuntimeException('This invite has already been accepted.');
                }

                throw new RuntimeException('This invite has already been used.');
            }

            if ($lockedInvite->status === 'declined') {
                return $lockedInvite->fresh(['brandProfessional', 'claimedProfessional']);
            }

            if ($lockedInvite->status !== 'pending') {
                throw new RuntimeException('This invite is no longer available.');
            }

            if ($lockedInvite->expires_at && $lockedInvite->expires_at->isPast()) {
                throw new RuntimeException('This invite has expired.');
            }

            $this->assertInviteMatchesProfessional($lockedInvite, $professional);

            $lockedInvite->status = 'declined';
            $lockedInvite->save();

            return $lockedInvite->fresh(['brandProfessional', 'claimedProfessional']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{invite: BrandAffiliateInvite, action: string}
     */
    private function upsertInvite(Professional $brand, array $attributes): array
    {
        $attributes = $this->normalizeInviteAttributes($attributes);
        $normalizedEmail = $this->normalizeEmail($attributes['email'] ?? null);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($brand, $attributes, $normalizedEmail): array {
                    $brandId = (string) $brand->id;

                    if ($normalizedEmail !== null) {
                        $this->expirePendingInvitesByBrandEmail($brandId, $normalizedEmail);
                        $this->assertEmailNotConnectedToBrand($brand, $normalizedEmail);

                        $refreshCandidate = BrandAffiliateInvite::query()
                            ->where('brand_professional_id', $brandId)
                            ->where('email_lc', $normalizedEmail)
                            ->whereIn('status', self::NON_ACCEPTED_STATUSES)
                            ->orderByDesc('created_at')
                            ->orderByDesc('id')
                            ->lockForUpdate()
                            ->first();

                        if ($refreshCandidate) {
                            $refreshedInvite = $this->refreshInviteRecord($refreshCandidate, $attributes, $normalizedEmail);

                            return [
                                'invite' => $refreshedInvite->fresh(['brandProfessional', 'claimedProfessional']),
                                'action' => 'refreshed',
                            ];
                        }
                    }

                    $invite = new BrandAffiliateInvite([
                        'brand_professional_id' => $brandId,
                        'token' => $this->generateUniqueToken(),
                        'status' => 'pending',
                        'invite_type' => $this->determineInviteType(
                            $attributes['email'] ?? null,
                            $attributes['first_name'] ?? null,
                            $attributes['last_name'] ?? null,
                        ),
                        'email' => $attributes['email'] ?? null,
                        'email_lc' => $normalizedEmail,
                        'phone' => $attributes['phone'] ?? null,
                        'first_name' => $attributes['first_name'] ?? null,
                        'last_name' => $attributes['last_name'] ?? null,
                        'message' => $attributes['message'] ?? null,
                        'expires_at' => $this->resolveExpiresAt($attributes),
                    ]);

                    $invite->save();

                    return [
                        'invite' => $invite->fresh(['brandProfessional', 'claimedProfessional']),
                        'action' => 'created',
                    ];
                });
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() !== '23505') {
                    throw $exception;
                }

                // Retry on token/pending uniqueness races.
                if ($attempt === 2) {
                    throw new RuntimeException('Unable to create or refresh invite right now. Please try again.');
                }
            }
        }

        throw new RuntimeException('Unable to create or refresh invite right now. Please try again.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function refreshInviteRecord(BrandAffiliateInvite $invite, array $attributes, string $normalizedEmail): BrandAffiliateInvite
    {
        $invite->status = 'pending';
        $invite->claimed_professional_id = null;
        $invite->accepted_at = null;
        $invite->expires_at = $this->resolveExpiresAt($attributes);
        $invite->email = $attributes['email'] ?? $invite->email;
        $invite->email_lc = $normalizedEmail;

        foreach (['phone', 'first_name', 'last_name', 'message'] as $field) {
            if ($this->hasNonEmptyAttribute($attributes, $field)) {
                $invite->{$field} = trim((string) $attributes[$field]);
            }
        }

        $invite->invite_type = $this->determineInviteType(
            $invite->email,
            $invite->first_name,
            $invite->last_name,
        );

        $invite->save();

        return $invite;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveExpiresAt(array $attributes): ?\DateTimeInterface
    {
        $raw = array_key_exists('expiration', $attributes)
            ? mb_strtolower(trim((string) ($attributes['expiration'] ?? '')))
            : '';

        $expiration = $raw === '' ? '30d' : $raw;

        return match ($expiration) {
            '24h' => now()->addHours(24),
            '7d' => now()->addDays(7),
            '30d' => now()->addDays(30),
            'none' => null,
            default => throw new RuntimeException('Invalid expiration value.'),
        };
    }

    private function determineInviteType(?string $email, ?string $firstName, ?string $lastName): string
    {
        $hasPersonalisation =
            filled($email) ||
            filled($firstName) ||
            filled($lastName);

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
        if ($inviteEmail === '') {
            return;
        }

        $primaryEmail = $this->normalizeEmail((string) ($professional->primary_email ?? ''));
        $publicEmail = $this->normalizeEmail((string) ($professional->public_contact_email ?? ''));
        $matchesPrimary = is_string($primaryEmail) && $primaryEmail !== '' && $inviteEmail === $primaryEmail;
        $matchesPublic = is_string($publicEmail) && $publicEmail !== '' && $inviteEmail === $publicEmail;

        if (! $matchesPrimary && ! $matchesPublic) {
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

    /**
     * @param  array<int, string>  $professionalIds
     */
    private function brandHasConnectedProfessionals(Professional $brand, array $professionalIds): bool
    {
        if ($professionalIds === []) {
            return false;
        }

        return BrandPartnerLink::query()
            ->whereIn('affiliate_professional_id', $professionalIds)
            ->where('brand_professional_id', $brand->id)
            ->exists();
    }

    private function assertEmailNotConnectedToBrand(Professional $brand, string $normalizedEmail): void
    {
        $matchingProfessionalIds = Professional::withTrashed()
            ->where(function ($query) use ($normalizedEmail) {
                $query->whereRaw('LOWER(primary_email) = ?', [$normalizedEmail])
                    ->orWhereRaw('LOWER(public_contact_email) = ?', [$normalizedEmail]);
            })
            ->pluck('id')
            ->all();

        if ($this->brandHasConnectedProfessionals($brand, $matchingProfessionalIds)) {
            throw new RuntimeException('Email is already connected to this brand.');
        }
    }

    /**
     * @param  Collection<int, BrandAffiliateInvite>  $invites
     */
    private function notifyExistingEmailRecipientsBatch(Professional $brand, Collection $invites): void
    {
        $invitesByEmail = [];

        foreach ($invites as $invite) {
            if (! $invite instanceof BrandAffiliateInvite) {
                continue;
            }

            $normalizedEmail = $this->normalizeEmail((string) ($invite->email ?? ''));
            if ($normalizedEmail === null) {
                continue;
            }

            $invitesByEmail[$normalizedEmail] = $invite;
        }

        if ($invitesByEmail === []) {
            return;
        }

        $emails = array_keys($invitesByEmail);

        $professionals = Professional::query()
            ->where('status', 'active')
            ->where(function ($query) use ($emails) {
                $query->whereIn(DB::raw('LOWER(primary_email)'), $emails)
                    ->orWhereIn(DB::raw('LOWER(public_contact_email)'), $emails);
            })
            ->get(['id', 'primary_email', 'public_contact_email']);

        if ($professionals->isEmpty()) {
            return;
        }

        $brandName = trim((string) ($brand->display_name ?: $brand->handle ?: 'this brand'));
        $now = now();
        $notifications = [];

        foreach ($professionals as $professional) {
            $primaryEmail = $this->normalizeEmail((string) ($professional->primary_email ?? ''));
            $publicEmail = $this->normalizeEmail((string) ($professional->public_contact_email ?? ''));

            $matchingInvite = null;
            if ($primaryEmail !== null && isset($invitesByEmail[$primaryEmail])) {
                $matchingInvite = $invitesByEmail[$primaryEmail];
            } elseif ($publicEmail !== null && isset($invitesByEmail[$publicEmail])) {
                $matchingInvite = $invitesByEmail[$publicEmail];
            }

            if (! $matchingInvite instanceof BrandAffiliateInvite) {
                continue;
            }

            $notifications[] = [
                'professional_id' => $professional->id,
                'type' => 'Invitation',
                'title' => 'New brand partner invitation!',
                'body' => "You have a new invite to become a brand partner of {$brandName}",
                'cta_url' => "/brand-affiliate-invites/{$matchingInvite->token}/claim",
                'primary_action_label' => 'Accept Invitation',
                'secondary_action_label' => 'Decline Invitation',
                'secondary_action_url' => "/brand-affiliate-invites/{$matchingInvite->token}/decline",
                'severity' => Notification::severityForFrontendType('Invitation'),
                'starts_at' => $now,
                'ends_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($notifications !== []) {
            DB::table('notifications')->insert($notifications);
        }
    }

    private function expirePendingInvitesByBrandEmail(string $brandProfessionalId, string $normalizedEmail): void
    {
        BrandAffiliateInvite::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('status', 'pending')
            ->where('email_lc', $normalizedEmail)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);
    }

    private function expireInviteIfNeeded(BrandAffiliateInvite $invite): BrandAffiliateInvite
    {
        if ($invite->status !== 'pending') {
            return $invite;
        }

        if (! $invite->expires_at || ! $invite->expires_at->isPast()) {
            return $invite;
        }

        $invite->status = 'expired';
        $invite->save();

        return $invite;
    }

    /**
     * @param  mixed  $rawRow
     * @return array<string, mixed>
     */
    private function extractBulkInviteAttributes(mixed $rawRow): array
    {
        $row = is_array($rawRow) ? $rawRow : [];

        if (isset($row['attributes']) && is_array($row['attributes'])) {
            $attributeRow = $row['attributes'];
            if (array_key_exists('_row_number', $row) && ! array_key_exists('_row_number', $attributeRow)) {
                $attributeRow['_row_number'] = $row['_row_number'];
            }
            if (array_key_exists('row', $row) && ! array_key_exists('_row_number', $attributeRow)) {
                $attributeRow['_row_number'] = $row['row'];
            }

            $row = $attributeRow;
        }

        $attributes = [];
        foreach (['email', 'phone', 'first_name', 'last_name', 'message', 'expiration'] as $key) {
            if (array_key_exists($key, $row)) {
                $attributes[$key] = $row[$key];
            }
        }

        if (array_key_exists('_row_number', $row)) {
            $attributes['_row_number'] = $row['_row_number'];
        }

        return $this->normalizeInviteAttributes($attributes);
    }

    /**
     * @param  mixed  $rawRow
     */
    private function extractBulkRowNumber(mixed $rawRow, int $fallback): int
    {
        $row = is_array($rawRow) ? $rawRow : [];
        $candidate = $row['_row_number'] ?? $row['row'] ?? null;

        if (! is_numeric($candidate)) {
            return $fallback;
        }

        return max(1, (int) $candidate);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function bulkInviteRules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:500'],
            'expiration' => ['nullable', 'string', 'in:24h,7d,30d,none'],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeInviteAttributes(array $attributes): array
    {
        $normalized = [];

        foreach (['email', 'phone', 'first_name', 'last_name', 'message', 'expiration'] as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $value = $attributes[$field];
            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '') {
                $value = null;
            }

            $normalized[$field] = $value;
        }

        if (array_key_exists('_row_number', $attributes)) {
            $normalized['_row_number'] = $attributes['_row_number'];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function hasNonEmptyAttribute(array $attributes, string $field): bool
    {
        if (! array_key_exists($field, $attributes)) {
            return false;
        }

        return trim((string) $attributes[$field]) !== '';
    }

    private function rowErrorCodeForException(RuntimeException $exception): string
    {
        $message = mb_strtolower(trim($exception->getMessage()));

        if (str_contains($message, 'already connected')) {
            return 'already_connected_to_brand';
        }

        if (str_contains($message, 'expiration')) {
            return 'invalid_expiration';
        }

        return 'invite_failed';
    }
}
