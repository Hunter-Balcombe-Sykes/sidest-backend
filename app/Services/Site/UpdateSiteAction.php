<?php

namespace App\Services\Site;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\ProfessionalHandleAlias;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Models\Core\Site\Theme;
use App\Services\Cache\SiteCacheService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// V2: Site update with business logic — subdomain cooldown (30-day), theme defaults, PATCH-style settings merge, publish validation.
class UpdateSiteAction
{
    // Days between allowed subdomain changes. Mirrored in ProfessionalController::show
    // when computing subdomain_change_available_at for the /me payload.
    public const SUBDOMAIN_COOLDOWN_DAYS = 30;

    /**
     * Updates the given professional's site.
     *
     * $data should already be validated by a FormRequest.
     * $options can enable staff-only powers later without changing pro-behavior.
     */
    public function execute(Professional $professional, array $data, array $options = []): Site
    {
        $professional->loadMissing('site');

        $site = $professional->site;
        if (! $site) {
            throw ValidationException::withMessages([
                'site' => ['Professional has no site.'],
            ]);
        }

        $allowForcePublish = (bool) ($options['allow_force_publish'] ?? false);
        $forcePublish = (bool) ($data['force_publish'] ?? false);
        $allowSubdomainOverride = (bool) ($options['allow_subdomain_override'] ?? false);

        // IMPORTANT: never pass non-column fields into fill()
        unset($data['force_publish']);

        return DB::transaction(function () use ($professional, $site, $data, $allowForcePublish, $forcePublish, $allowSubdomainOverride): Site {
            if (array_key_exists('subdomain', $data)) {
                $incoming = strtolower($data['subdomain']);
                $current = strtolower((string) $site->subdomain);

                if ($incoming === $current) {
                    unset($data['subdomain']);
                } else {
                    if (! $allowSubdomainOverride && $site->subdomain_changed_at) {
                        $nextAllowed = $site->subdomain_changed_at->copy()->addDays(self::SUBDOMAIN_COOLDOWN_DAYS);

                        if (Carbon::now()->lt($nextAllowed)) {
                            throw ValidationException::withMessages([
                                'subdomain' => ['You can change your subdomain again on '.$nextAllowed->toDateString().'.'],
                            ]);
                        }
                    }

                    $conflictInSites = DB::table('site.sites')
                        ->whereRaw('lower(subdomain) = ?', [$incoming])
                        ->where('id', '!=', $site->id)
                        ->exists();

                    if ($conflictInSites) {
                        throw ValidationException::withMessages([
                            'subdomain' => ['This subdomain is already taken.'],
                        ]);
                    }

                    $conflictInAliases = DB::table('site.site_subdomain_aliases')
                        ->whereRaw('lower(subdomain) = ?', [$incoming])
                        ->exists();

                    if ($conflictInAliases) {
                        throw ValidationException::withMessages([
                            'subdomain' => ['This subdomain is already taken.'],
                        ]);
                    }

                    if (! empty($site->subdomain)) {
                        // Nested transaction = SAVEPOINT on Postgres. Without this, a 23505
                        // duplicate error aborts the outer transaction even when caught in PHP.
                        try {
                            DB::transaction(function () use ($site) {
                                SiteSubdomainAlias::query()->create([
                                    'site_id' => $site->id,
                                    'subdomain' => $site->subdomain,
                                    'created_at' => now(),
                                ]);
                            });
                        } catch (QueryException $e) {
                            if ($e->getCode() !== '23505') {
                                throw $e;
                            }
                            // Duplicate alias is fine — uniqueness enforced in DB.
                        }
                    }

                    // Keep the canonical handle on the professional in sync with
                    // the subdomain. HydrogenAffiliateController + the public site
                    // resolver both look up by handle_lc, so a desync here means
                    // the affiliate URL stops working immediately after a rename.
                    // Mirror the old handle into professional_handle_aliases so
                    // links shared on the old URL still resolve.
                    $oldHandle = $professional->handle;
                    if (! empty($oldHandle) && strtolower($oldHandle) !== $incoming) {
                        try {
                            DB::transaction(function () use ($professional, $oldHandle) {
                                ProfessionalHandleAlias::query()->create([
                                    'professional_id' => $professional->id,
                                    'handle' => $oldHandle,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            });
                        } catch (QueryException $e) {
                            if ($e->getCode() !== '23505') {
                                throw $e;
                            }
                            // Duplicate alias is fine — uniqueness enforced in DB.
                        }
                    }
                    $professional->forceFill([
                        'handle' => $incoming,
                        'handle_lc' => $incoming,
                    ])->save();

                    $data['subdomain'] = $incoming;
                    $site->subdomain_changed_at = now();
                }
            }

            // Allow sending theme_id=null to reset to default (same behavior as current pro-controller)
            if (array_key_exists('theme_id', $data) && $data['theme_id'] === null) {
                $defaultId = Theme::query()
                    ->where('is_default', true)
                    ->value('id');

                if (! $defaultId) {
                    throw ValidationException::withMessages([
                        'theme_id' => ['No default theme configured.'],
                    ]);
                }

                $data['theme_id'] = $defaultId;
            }

            // Merge settings for PATCH semantics (don’t overwrite the whole JSON)
            if (array_key_exists('settings', $data)) {
                $existing = is_array($site->settings) ? $site->settings : [];
                $incoming = is_array($data['settings']) ? $data['settings'] : [];
                // Product selections are stored in commerce.affiliate_product_selections, not site settings JSON.
                unset($incoming['selected_products']);
                Arr::forget($incoming, 'design.typography.font_file_name');
                Arr::forget($incoming, 'design.typography.font_file_path');
                Arr::forget($incoming, 'design.typography.font_file_url');
                $merged = array_replace_recursive($existing, $incoming);

                // Indexed list special-casing was historically needed for
                // settings.design.media.placeholder_sitepage_images, which has
                // since moved to site.site_media. No remaining indexed lists live
                // under settings, so the recursive merge is sufficient.

                $data['settings'] = $merged;
            }

            // If publishing, enforce completeness unless staff force_publish is allowed + true
            if (($data['is_published'] ?? null) === true) {
                $canBypass = $allowForcePublish && $forcePublish;

                if (! $canBypass) {
                    // Must have display name
                    if (empty($professional->display_name)) {
                        throw ValidationException::withMessages([
                            'is_published' => ['Cannot publish: professional must have a display name.'],
                        ]);
                    }

                }
            }

            // Future: staff-only overrides could go here (options['allow_force_publish'] etc.)

            $site->fill($data);
            try {
                $site->save();
            } catch (QueryException $e) {
                // If you have a unique index on subdomain, this is your final safety net.
                if ($e->getCode() === '23505') {
                    throw ValidationException::withMessages([
                        'subdomain' => ['This subdomain is already taken.'],
                    ]);
                }
                throw $e;
            }

            // Bust the Hydrogen brand-design cache so dashboard saves surface
            // inside Hydrogen's 5s staleWhileRevalidate window. Deferred until
            // commit so a rolled-back transaction doesn't wipe a warm cache.
            $siteId = (string) $site->id;
            DB::afterCommit(fn () => app(SiteCacheService::class)->forgetBrandDesign($siteId));

            return $site->fresh();
        });
    }
}
