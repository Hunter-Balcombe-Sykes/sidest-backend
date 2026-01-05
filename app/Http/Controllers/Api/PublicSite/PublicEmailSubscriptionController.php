<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PublicSite\PublicEmailSubscribeRequest;
use App\Models\Core\Notifications\EmailSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\PublicSiteResolver;
use App\Http\Controllers\Concerns\HashesClientData;

class PublicEmailSubscriptionController extends Controller
{
    use HashesClientData;
    public function subscribe(PublicEmailSubscribeRequest $request, PublicSiteResolver $resolver): JsonResponse
    {
        $data = $request->validated();

        $subdomain = $this->resolveSubdomainFromHost($request);
        if (!$subdomain) {
            return response()->json(['message' => 'Could not determine site from URL.'], 400);
        }

        $site = $resolver->resolvePublishedSite($subdomain);

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
}
