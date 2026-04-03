<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Cache\ProfessionalCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use App\Services\Professional\BrandAffiliateInviteService;
use App\Services\Professional\BrandPartnerLinkService;
use App\Services\Legal\ProfessionalLegalContentService;
use App\Services\Professional\AccountTypeDefaultsService;
use App\Services\Professional\SiteProvisioningService;



// V2: Account signup/update. Creates professional + site, applies type defaults, handles affiliate invite claims and brand partner connections. Entry point for affiliate/professional signup.
class BootstrapController extends ApiController
{
    public function __construct(
        private readonly SiteProvisioningService $siteProvisioning,
    ) {}

    public function bootstrap(
        BootstrapRequest $request,
        ProfessionalLegalContentService $legalContentService,
        BrandAffiliateInviteService $brandAffiliateInviteService,
        BrandPartnerLinkService $brandPartnerLinks,
        AccountTypeDefaultsService $accountTypeDefaultsService
    )
    {
        $uid = $request->attributes->get('supabase_uid');
        if (!is_string($uid) || $uid === '') {
            return $this->error('Unauthenticated', 401);
        }

        if ($this->isWaitlistModeEnabled() && ! $this->hasExistingProfessional($uid)) {
            return $this->error(
                'New account creation is currently waitlist-only. Please join the waitlist.',
                403,
                ['code' => 'WAITLIST_ONLY']
            );
        }

        $data = $request->validated();

        try {
            $allowedProfessionalTypes = array_keys(config('comet.professional_types', []));
            $resolveProfessionalType = static function (mixed $candidate) use ($allowedProfessionalTypes): string {
                if (is_string($candidate)) {
                    $normalized = mb_strtolower(trim($candidate));
                    if ($normalized !== '' && in_array($normalized, $allowedProfessionalTypes, true)) {
                        return $normalized;
                    }
                }

                return 'professional';
            };

            $result = DB::transaction(function () use ($uid, $data, $legalContentService, $brandAffiliateInviteService, $brandPartnerLinks, $accountTypeDefaultsService, $resolveProfessionalType) {
            $createdProfessional = false;

            $professional = Professional::query()->where('auth_user_id', $uid)->first();

            if (!$professional) {
                $createdProfessional = true;
                $professional = new Professional([
                    'handle'          => $data['handle'],
                    'display_name'    => $data['display_name'],
                    'bio'             => null,
                    'country_code'    => $data['country_code'] ?? null,
                    'timezone'        => $data['timezone'] ?? null,
                    'professional_type' => $resolveProfessionalType($data['professional_type'] ?? null),
                    'status'          => 'active',
                    'onboarding_step' => 0,
                    'qr_slug'         => $this->siteProvisioning->generateQrSlug($data['handle'] ?? null),
                    'phone' => $data['phone'] ?? null,
                    'primary_email'   => $data['primary_email'],
                    'first_name'      => $data['first_name'] ?? '',
                    'last_name'       => $data['last_name'] ?? null,

                    'public_contact_number' => null,
                    'public_contact_email' => null,
                    'handle_lc' => $data['handle_lc'],
                ]);
                $professional->auth_user_id = $uid;
            } else {

                if (in_array($professional->status, ['disabled', 'suspended'], true)) {
                    return $this->error('Account is disabled. Contact support.', 403);
                }

                $fill = [
                    'handle'        => $data['handle'],
                    'display_name'  => $data['display_name'],
                    'primary_email' => $data['primary_email'],
                    'phone'         => $data['phone'] ?? $professional->phone,
                    'first_name'    => $data['first_name'] ?? $professional->first_name,
                    'last_name'     => $data['last_name'] ?? $professional->last_name,
                    'country_code'  => $data['country_code'] ?? $professional->country_code,
                    'timezone'      => $data['timezone'] ?? $professional->timezone,
                    'professional_type' => $resolveProfessionalType($data['professional_type'] ?? $professional->professional_type),
                    'handle_lc' => $data['handle_lc'],
                ];

                if (array_key_exists('phone', $data)) {
                    $fill['phone'] = $data['phone'];
                }

                $professional->fill($fill);
            }
            if (!is_string($professional->qr_slug) || $professional->qr_slug === '') {
                $professional->qr_slug = $this->siteProvisioning->generateQrSlug($professional->handle ?? null);
            }
            $professional->save();

            // Add to Comet updates list once (global list). Do NOT overwrite if they already unsubscribed.
            $this->ensureCometUpdatesSubscription($professional->primary_email);


            $site = Site::query()->where('professional_id', $professional->id)->first();

            if (!$site) {
                $base = $this->siteProvisioning->subdomainBaseFromHandle($data['handle']);

                $site = $this->siteProvisioning->createSiteWithRetry($professional->id, $base);
            }

            // Apply account-type defaults for new professionals
            if ($createdProfessional) {
                $accountTypeDefaultsService->applyDefaults($professional, $site);

                if ($professional->professional_type === 'brand') {
                    BrandProfile::firstOrCreate(
                        ['professional_id' => (string) $professional->id],
                        ['setup_complete' => false]
                    );
                }
            }

                if (is_string($data['invite_token'] ?? null) && trim((string) $data['invite_token']) !== '') {
                    $invite = $brandAffiliateInviteService->findByToken((string) $data['invite_token']);
                    if (! $invite) {
                    throw new RuntimeException('Invite not found.');
                }

                $brandAffiliateInviteService->claimInvite($invite, $professional);
                $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, (string) $professional->id);
                $accountTypeDefaultsService->applyAffiliateDefaults($professional, $site, (string) $invite->brand_professional_id);
                } elseif (is_string($data['brand_partner_professional_id'] ?? null) && trim((string) $data['brand_partner_professional_id']) !== '') {
                    $brandPartnerProfessional = Professional::query()
                        ->whereKey((string) $data['brand_partner_professional_id'])
                    ->where('professional_type', 'brand')
                    ->first();

                    if (! $brandPartnerProfessional) {
                        throw new RuntimeException('Brand partner not found.');
                    }

                    $affiliateId = (string) $professional->id;
                    $brandId = (string) $brandPartnerProfessional->id;

                    if (! $brandPartnerLinks->isConnected($affiliateId, $brandId)) {
                        $brandPartnerLinks->connectBrandToAffiliate($affiliateId, $brandId);
                    }

                    $brandPartnerLinks->promoteBrandToPrimary($affiliateId, $brandId);
                    $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, $affiliateId);
                    $accountTypeDefaultsService->applyAffiliateDefaults($professional, $site, $brandId);
                }

            $legalContentService->refreshGenerated($professional, $site);
            app(ProfessionalCacheService::class)->invalidateProfessional($professional);

            // Ensure the professional has a subscription – seed the free plan if none exists
            $this->siteProvisioning->ensureFreeSubscription($professional);

            if ($createdProfessional) {
                $this->createWelcomeNotification($professional);
            }

                return [
                    'professional' => $professional->fresh(),
                    'site' => $site->fresh(),
                ];
            });
        } catch (\Exception $e) {
            Log::error('Bootstrap transaction failed', [
                'error' => $e->getMessage(),
                'uid' => $uid,
            ]);
            throw $e;
        }

        return $this->success($result);
    }

    private function ensureCometUpdatesSubscription(?string $email): void
    {
        $email = is_string($email) ? strtolower(trim($email)) : '';
        if ($email === '') return;

        $listKey = 'comet_updates';

        $existing = EmailSubscription::query()
            ->whereNull('professional_id')
            ->where('list_key', $listKey)
            ->where('email_lc', $email)
            ->first();

        if ($existing) {
            return; // keep whatever status they chose
        }

        $sub = new EmailSubscription([
            'professional_id' => null,
            'list_key' => $listKey,
            'email' => $email,
            'email_lc' => $email,
            'full_name' => null,
            'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
        ]);

        $sub->markSubscribed(['source' => 'bootstrap']);
        $sub->save();
    }

    private function createWelcomeNotification(Professional $professional): void
    {
        Notification::query()->firstOrCreate(
            [
                'professional_id' => $professional->id,
                'type' => 'Info',
                'title' => 'Welcome to Sight',
            ],
            [
                'body' => 'Welcome to Sight. This is placeholder content for now.',
                'cta_url' => null,
                'severity' => 'info',
                'starts_at' => now(),
                'ends_at' => null,
            ]
        );
    }

    private function syncSiteBrandPartnerSettings(
        Site $site,
        BrandPartnerLinkService $brandPartnerLinks,
        string $affiliateProfessionalId
    ): void {
        $links = $brandPartnerLinks->getLinksForAffiliate($affiliateProfessionalId);
        $settings = is_array($site->settings) ? $site->settings : [];

        $brandPartner = is_array($settings['brand_partner'] ?? null)
            ? $settings['brand_partner']
            : [];

        $primaryLink = $links->firstWhere('slot', BrandPartnerLinkService::PRIMARY_SLOT);
        if ($primaryLink) {
            $brandPartner['professional_id'] = (string) $primaryLink->brand_professional_id;
        } else {
            unset($brandPartner['professional_id'], $brandPartner['professionalId']);
        }

        $settings['brand_partner'] = $brandPartner;
        $settings['additional_brand_partners'] = $links
            ->filter(static fn ($link): bool => (int) $link->slot > BrandPartnerLinkService::PRIMARY_SLOT)
            ->sortBy('slot')
            ->map(static fn ($link): array => [
                'professional_id' => (string) $link->brand_professional_id,
            ])
            ->values()
            ->all();

        $site->settings = $settings;
        $site->save();
    }

    private function isWaitlistModeEnabled(): bool
    {
        return (bool) config('comet.waitlist.enabled', false);
    }

    private function hasExistingProfessional(string $uid): bool
    {
        return Professional::query()
            ->where('auth_user_id', $uid)
            ->exists();
    }
}
