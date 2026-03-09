<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Http\Requests\Api\PublicSite\PublicEmailSubscribeRequest;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Professional\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Public\PublicSiteResolver;

class PublicEmailSubscriptionController extends ApiController
{
    use HashesClientData;
    use ResolvesSubdomainFromHost;

    public function subscribe(PublicEmailSubscribeRequest $request, PublicSiteResolver $resolver): JsonResponse
    {
        $data = $request->validated();

        $subdomain = $this->resolveSiteSubdomain($request);
        if (!$subdomain) {
            return $this->error('Could not determine site from URL.', 400);
        }

        $site = $resolver->resolvePublishedSite($subdomain);

        if (!$site) {
            return $this->error('Site not found.', 404);
        }

        $listKey = $data['list_key'] ?? 'marketing';

        $email = strtolower(trim($data['email']));
        $now   = now();

        // Only update full_name if provided (so blank submissions don’t wipe it)
        $updateCols = [
            'status',
            'subscribed_at',
            'unsubscribed_at',
            'consent_source',
            'consent_ip_hash',
            'consent_user_agent',
            'updated_at',
        ];

        if (!empty($data['full_name'])) {
            $updateCols[] = 'full_name';
        }

        EmailSubscription::query()->upsert(
            [[
                'professional_id' => $site->professional_id,
                'list_key' => $listKey,

                'email' => $email,
                'email_lc' => $email, // <-- requires the migration adding email_lc

                'full_name' => $data['full_name'] ?? null,

                'status' => 'subscribed',
                'subscribed_at' => $now,
                'unsubscribed_at' => null,

                // Set token on insert only (NOT in $updateCols)
                'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),

                'consent_source' => 'site_subscribe',
                'consent_ip_hash' => $this->hashIp($request->ip()),
                'consent_user_agent' => $request->userAgent(),

                'created_at' => $now,
                'updated_at' => $now,
            ]],
            // Must match your UNIQUE index: (professional_id, list_key, email_lc)
            ['professional_id', 'list_key', 'email_lc'],
            $updateCols
        );

        try {
            $this->upsertMarketingCustomer(
                (string) $site->professional_id,
                $email,
                $data['full_name'] ?? null,
            );
        } catch (\Throwable $exception) {
            // Do not block successful subscription if customer sync fails.
            Log::warning('Public subscribe customer upsert failed', [
                'professional_id' => (string) $site->professional_id,
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);
        }


        return $this->success([
            'ok' => true,
            'subscribed' => true,
            'list_key' => $listKey,
        ]);
    }

    private function resolveSiteSubdomain(Request $request): ?string
    {
        $fromHeader = trim((string) $request->header('X-Site-Subdomain', ''));
        if ($fromHeader !== '') {
            return strtolower($fromHeader);
        }

        $fromQuery = trim((string) $request->query('subdomain', ''));
        if ($fromQuery !== '') {
            return strtolower($fromQuery);
        }

        $fromSlugQuery = trim((string) $request->query('slug', ''));
        if ($fromSlugQuery !== '') {
            return strtolower($fromSlugQuery);
        }

        $fromInput = trim((string) $request->input('subdomain', ''));
        if ($fromInput !== '') {
            return strtolower($fromInput);
        }

        $fromSlugInput = trim((string) $request->input('slug', ''));
        if ($fromSlugInput !== '') {
            return strtolower($fromSlugInput);
        }

        $fromHost = $this->resolveSubdomainFromHost($request);
        if ($fromHost) {
            return strtolower($fromHost);
        }

        return null;
    }

    private function upsertMarketingCustomer(string $professionalId, string $email, ?string $fullName): void
    {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            return;
        }

        $name = is_string($fullName) ? trim($fullName) : '';

        $existing = Customer::query()
            ->withTrashed()
            ->where('professional_id', $professionalId)
            ->whereRaw('lower(email) = ?', [$normalizedEmail])
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            if ($name !== '') {
                $existing->full_name = $name;
            }

            if (($existing->source ?? '') === '') {
                $existing->source = 'site';
            }

            $existing->save();
            return;
        }

        $customer = new Customer();
        $customer->professional_id = $professionalId;
        $customer->email = $normalizedEmail;
        $customer->full_name = $name !== '' ? $name : null;
        $customer->source = 'site';
        $customer->save();
    }
}
