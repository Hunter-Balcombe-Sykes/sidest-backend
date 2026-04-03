<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Notifications\EmailSubscription;
use App\Services\Public\PublicSiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Marketing email preference management — check status, unsubscribe, and resubscribe via token.
class PublicMarketingPreferenceController extends ApiController
{
    /**
     * GET /api/public/marketing-preference
     * Check current marketing preference status for an email.
     * Query params:
     *   - email: customer email (required)
     *   - subdomain: site subdomain (required to identify professional)
     */
    public function show(Request $request, PublicSiteResolver $resolver): JsonResponse
    {
        $email = $request->query('email');
        $subdomain = $request->query('subdomain');

        if (!$email || !$subdomain) {
            return $this->error('Missing email or subdomain parameter', 400);
        }

        $email = strtolower(trim($email));
        $subdomain = strtolower(trim($subdomain));

        // Resolve site to professional
        $site = $resolver->resolvePublishedSite($subdomain);

        if (!$site || !$site->professional_id) {
            return $this->error('Site not found or not linked to professional', 404);
        }

        // Check marketing subscription status
        $subscription = EmailSubscription::query()
            ->where('professional_id', $site->professional_id)
            ->where('list_key', 'marketing')
            ->where('email_lc', $email)
            ->first();

        return $this->success([
            'email' => $email,
            'opted_in' => $subscription?->status === 'subscribed',
            'status' => $subscription?->status ?? 'unknown', // subscribed, unsubscribed, bounced, complained, unknown
        ]);
    }

    /**
     * POST /api/public/unsubscribe/:token
     * Unsubscribe from marketing emails using token from email link.
     */
    public function unsubscribe(Request $request, string $token): JsonResponse
    {
        if (!$token || strlen($token) < 10) {
            return $this->error('Invalid unsubscribe token', 400);
        }

        $subscription = EmailSubscription::query()
            ->where('unsubscribe_token', $token)
            ->where('list_key', 'marketing')
            ->first();

        if (!$subscription) {
            return $this->error('Unsubscribe token not found or already processed', 404);
        }

        // Mark as unsubscribed
        $subscription->markUnsubscribed();
        $subscription->save();

        return $this->success([
            'message' => 'Successfully unsubscribed from marketing emails',
            'email' => $subscription->email,
        ]);
    }

    /**
     * POST /api/public/subscribe/:token
     * Resubscribe to marketing emails using the same token.
     */
    public function resubscribe(Request $request, string $token): JsonResponse
    {
        if (!$token || strlen($token) < 10) {
            return $this->error('Invalid token', 400);
        }

        $subscription = EmailSubscription::query()
            ->where('unsubscribe_token', $token)
            ->where('list_key', 'marketing')
            ->first();

        if (!$subscription) {
            return $this->error('Token not found', 404);
        }

        // Mark as subscribed again
        $subscription->markSubscribed();
        $subscription->save();

        return $this->success([
            'message' => 'Successfully resubscribed to marketing emails',
            'email' => $subscription->email,
        ]);
    }
}
