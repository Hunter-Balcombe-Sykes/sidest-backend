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
use Illuminate\Support\Facades\Schema;
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
        $subscription = EmailSubscription::query()
            ->where('professional_id', $site->professional_id)
            ->where('list_key', $listKey)
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        if (!$subscription) {
            $subscription = new EmailSubscription([
                'professional_id' => $site->professional_id,
                'list_key' => $listKey,
                'email' => $email,
                'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
            ]);
        } else {
            $subscription->email = $email;
            if (!$subscription->unsubscribe_token) {
                $subscription->unsubscribe_token = EmailSubscription::newUnsubscribeToken();
            }
        }

        if (!empty($data['full_name'])) {
            $subscription->full_name = $data['full_name'];
        }

        if ($this->emailLcColumnExists()) {
            $subscription->email_lc = $email;
        }

        $subscription->markSubscribed([
            'source' => 'site_subscribe',
            'ip_hash' => $this->hashIp($request->ip()),
            'user_agent' => $request->userAgent(),
        ]);
        $subscription->save();

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

    private function emailLcColumnExists(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $cached = Schema::hasColumn('email_subscriptions', 'email_lc')
            || Schema::hasColumn('core.email_subscriptions', 'email_lc');

        return $cached;
    }
}
