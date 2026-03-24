<?php

namespace App\Actions\Site;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Models\Core\Site\Theme;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\Core\Site\Site;

class UpdateSiteAction
{
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
        if (!$site) {
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
                    if (!$allowSubdomainOverride && $site->subdomain_changed_at) {
                        $nextAllowed = $site->subdomain_changed_at->copy()->addDays(30);

                        if (Carbon::now()->lt($nextAllowed)) {
                            throw ValidationException::withMessages([
                                'subdomain' => ['You can change your subdomain again on ' . $nextAllowed->toDateString() . '.'],
                            ]);
                        }
                    }

                    $conflictInSites = DB::table('sites')
                        ->whereRaw('lower(subdomain) = ?', [$incoming])
                        ->where('id', '!=', $site->id)
                        ->exists();

                    if ($conflictInSites) {
                        throw ValidationException::withMessages([
                            'subdomain' => ['This subdomain is already taken.'],
                        ]);
                    }

                    $conflictInAliases = DB::table('site_subdomain_aliases')
                        ->whereRaw('lower(subdomain) = ?', [$incoming])
                        ->exists();

                    if ($conflictInAliases) {
                        throw ValidationException::withMessages([
                            'subdomain' => ['This subdomain is already taken.'],
                        ]);
                    }

                    if (!empty($site->subdomain)) {
                        try {
                            SiteSubdomainAlias::query()->create([
                                'site_id'   => $site->id,
                                'subdomain' => $site->subdomain,
                                'created_at' => now(),
                            ]);
                        } catch (QueryException $e) {
                            if ($e->getCode() !== '23505') {
                                throw $e;
                            }
                            // Ignore duplicate alias writes; uniqueness is enforced in the DB.
                        }
                    }

                    $data['subdomain'] = $incoming;
                    $site->subdomain_changed_at = now();
                }
            }

        // Allow sending theme_id=null to reset to default (same behavior as current pro-controller)
        if (array_key_exists('theme_id', $data) && $data['theme_id'] === null) {
            $defaultId = Theme::query()
                ->where('is_default', true)
                ->value('id');

            if (!$defaultId) {
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
            // Product selections are stored in retail.professional_selections, not site settings JSON.
            unset($incoming['selected_products']);
            Arr::forget($incoming, 'design.typography.font_file_name');
            Arr::forget($incoming, 'design.typography.font_file_path');
            Arr::forget($incoming, 'design.typography.font_file_url');
            $data['settings'] = array_replace_recursive($existing, $incoming);
        }

        // If publishing, enforce completeness unless staff force_publish is allowed + true
        if (($data['is_published'] ?? null) === true) {
            $canBypass = $allowForcePublish && $forcePublish;

            if (!$canBypass) {
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

        return $site->fresh();
        });
    }
}
