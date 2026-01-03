<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\PublicEmailSubscribeRequest;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicEmailSubscriptionController extends Controller
{
    public function subscribe(PublicEmailSubscribeRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Resolve site from host (same pattern as your public lead controller)
        $subdomain = $this->resolveSubdomainFromHost($request);
        if (!$subdomain) {
            return response()->json(['message' => 'Could not determine site from URL.'], 400);
        }
        $subdomain = strtolower($subdomain);

        // Only allow subscribing for published sites (reduces spam / random subscription)
        $site = Site::query()->published()
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        if (!$site) {
            $alias = SiteSubdomainAlias::query()
                ->whereRaw('lower(subdomain) = ?', [$subdomain])
                ->first();

            if ($alias) {
                $site = Site::query()->published()->find($alias->site_id);
            }
        }

        if (!$site) {
            return response()->json(['message' => 'Site not found.'], 404);
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


        return response()->json([
            'ok' => true,
            'subscribed' => true,
            'list_key' => $listKey,
        ]);
    }

    public function unsubscribe(Request $request, string $token): JsonResponse
    {
        $subscription = EmailSubscription::query()
            ->where('unsubscribe_token', $token)
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'Invalid or expired unsubscribe link.'], 404);
        }

        if ($subscription->status !== 'unsubscribed') {
            $subscription->markUnsubscribed();
            $subscription->save();
        }

        return response()->json(['ok' => true, 'unsubscribed' => true]);
    }

    private function resolveSubdomainFromHost(Request $request): ?string
    {
        $host = $request->getHost(); // e.g. barber name.ours.com
        $publicDomain = config('comet.public_domain');

        if (!$publicDomain || !str_ends_with($host, $publicDomain)) {
            return null;
        }

        $suffix = '.' . ltrim($publicDomain, '.');
        if (!str_ends_with($host, $suffix)) {
            return null;
        }

        $sub = substr($host, 0, -strlen($suffix));
        return $sub !== '' ? $sub : null;
    }

    private function hashIp(?string $ip): ?string
    {
        if (!$ip) return null;
        // Avoid storing raw IP; HMAC with an app-key
        return hash_hmac('sha256', $ip, config('app.key'));
    }
}
