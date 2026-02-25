<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;



class BootstrapController extends ApiController
{
    public function bootstrap(BootstrapRequest $request)
    {
        $uid = $request->attributes->get('supabase_uid');
        if (!is_string($uid) || $uid === '') {
            return $this->error('Unauthenticated', 401);
        }

        $data = $request->validated();

        try {
            $result = DB::transaction(function () use ($uid, $data) {

            $professional = Professional::query()->where('auth_user_id', $uid)->first();

            if (!$professional) {
                $professional = new Professional([
                    'handle'          => $data['handle'],
                    'display_name'    => $data['display_name'],
                    'bio'             => null,
                    'country_code'    => $data['country_code'] ?? null,
                    'timezone'        => $data['timezone'] ?? null,
                    'status'          => 'active',
                    'onboarding_step' => 0,
                    'qr_slug'         => $this->generateQrSlug($data['handle'] ?? null),
                    'phone' => $data['phone'] ?? null,
                    'primary_email'   => $data['primary_email'],
                    'first_name'      => $data['first_name'] ?? '',
                    'last_name'       => $data['last_name'] ?? null,

                    // Defaults to Main phone/email if NULL
                    'public_contact_number' => $data['phone'] ?? null,
                    'public_contact_email' => $data['primary_email'] ?? null,
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
                    'handle_lc' => $data['handle_lc'],
                ];

                if (array_key_exists('phone', $data)) {
                    $fill['phone'] = $data['phone'];
                    // only change public_contact_number if phone is being set in this request
                    $fill['public_contact_number'] = $data['phone'] ?: null;
                }

                if (array_key_exists('primary_email', $data)) {
                    // only change public_contact_email if email is being set in this request
                    $fill['public_contact_email'] = $data['primary_email'] ?: null;
                }

                $professional->fill($fill);
            }
            if (!is_string($professional->qr_slug) || $professional->qr_slug === '') {
                $professional->qr_slug = $this->generateQrSlug($professional->handle ?? null);
            }
            $professional->save();

            // Add to Comet updates list once (global list). Do NOT overwrite if they already unsubscribed.
            $this->ensureCometUpdatesSubscription($professional->primary_email);


            $site = Site::query()->where('professional_id', $professional->id)->first();

            if (!$site) {
                $base = $this->subdomainBaseFromHandle($data['handle']);

                $site = $this->createSiteWithRetry($professional->id, $base);
            }

            // Ensure the professional has a subscription – seed the free plan if none exists
            $this->ensureFreeSubscription($professional);

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

    private function createSiteWithRetry(string $professionalId, string $base): Site
    {
        $reserved = array_map('strtolower', config('comet.reserved_subdomains', []));
        $base = strtolower($base);
        $baseIsReserved = in_array($base, $reserved, true);

        // If reserved: only try base-1...base-20
        if ($baseIsReserved) {
            for ($i = 1; $i <= 20; $i++) {
                $candidate = $this->buildCandidate($base, (string) $i);
                $site = $this->tryCreateSite($professionalId, $candidate);
                if ($site) return $site;
            }
        } else {
            // Not reserved: base, base-1...base-19
            for ($i = 0; $i < 20; $i++) {
                $suffix = $i === 0 ? null : (string) $i;
                $candidate = $this->buildCandidate($base, $suffix);
                $site = $this->tryCreateSite($professionalId, $candidate);
                if ($site) return $site;
            }
        }

        // Fallback: random suffix
        for ($i = 0; $i < 10; $i++) {
            $rand = Str::lower(Str::random(6));
            $candidate = $this->buildCandidate($base, $rand);
            $site = $this->tryCreateSite($professionalId, $candidate);
            if ($site) return $site;
        }

        throw new RuntimeException('Could not allocate a unique subdomain.');
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // Postgres unique_violation
        return $e->getCode() === '23505';
    }

    private function buildCandidate(string $base, ?string $suffix): string
    {
        if ($suffix === null) {
            return $base;
        }

        $base = $this->limitSubdomainBase($base, '-' . $suffix);
        return $base . '-' . $suffix;
    }

    private function limitSubdomainBase(string $base, string $suffixIncludingHyphen): string
    {
        // max subdomain length is 63
        $max = 63 - strlen($suffixIncludingHyphen);
        if ($max < 1) {
            return substr($base, 0, 1);
        }
        return substr($base, 0, $max);
    }

    private function subdomainBaseFromHandle(string $handle): string
    {
        $v = mb_strtolower(trim($handle));
        $v = preg_replace('/[^a-z0-9]+/', '-', $v);
        $v = trim($v, '-');

        // Generate UUID-based fallback if handle is empty
        if ($v === '') {
            $v = 'user-' . substr(Str::uuid()->toString(), 0, 8);
        }

        return $v;
    }

    private function tryCreateSite(string $professionalId, string $candidate): ?Site
    {
        try {
            $site = new Site([
                'subdomain'    => $candidate,
                'theme_id'     => null,
                'is_published' => false,
                'settings'     => [],
            ]);

            $site->professional_id = $professionalId;
            $site->save();

            return $site;
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null; // collision -> caller retries
            }
            throw $e;
        }
    }

    /**
     * Seed the free plan subscription if the professional has none.
     */
    private function ensureFreeSubscription(Professional $professional): void
    {
        // Reload the relationship in case it was cached as null during this transaction
        $professional->load('subscription');

        // Already has a current (non-ended) subscription – nothing to do
        if ($professional->subscription && $professional->subscription->ended_at === null) {
            return;
        }

        $freePlan = Plan::where('plan_key', 'free')->where('is_active', true)->first();
        if (!$freePlan) {
            Log::warning('No active free plan found – skipping subscription seed', [
                'professional_id' => $professional->id,
            ]);
            return;
        }

        Subscription::create([
            'id' => Str::uuid()->toString(),
            'professional_id' => $professional->id,
            'plan_id' => $freePlan->id,
            'provider' => 'internal',
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => null,
            'cancel_at_period_end' => false,
        ]);
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

    private function generateQrSlug(?string $handle): string
    {
        $base = is_string($handle) ? Str::slug($handle) : '';
        if ($base === '') {
            $base = 'pro';
        }

        // Retry with exponential backoff to handle race conditions
        $maxAttempts = 10;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $suffix = Str::lower(Str::random(6));
            $slug = $base . '-' . $suffix;

            try {
                // Check if slug exists (optimistic check before insert)
                if (!Professional::query()->where('qr_slug', $slug)->exists()) {
                    return $slug;
                }
            } catch (QueryException $e) {
                // If unique violation occurs during insert, keep retrying
                if ($this->isUniqueViolation($e)) {
                    continue;
                }
                throw $e;
            }
        }

        throw new RuntimeException('Could not generate a unique QR slug after ' . $maxAttempts . ' attempts.');
    }

}
