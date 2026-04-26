<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Notifications\EmailSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Marketing email preference center — token-authenticated read and unsubscribe.
// All endpoints accept the unsubscribe_token issued in the email footer; we never
// look up subscriptions by (email + subdomain) because that would let an arbitrary
// caller probe whether any email is subscribed to any site's marketing list.
class PublicMarketingPreferenceController extends ApiController
{
    /**
     * GET /api/public/marketing-preference?token=...
     * Returns the subscription status (and the email it belongs to) for the
     * matching unsubscribe token. Token-gated — no email/subdomain probing.
     */
    public function show(Request $request): JsonResponse
    {
        $token = trim((string) $request->query('token', ''));

        if (strlen($token) < 10) {
            return $this->error('Invalid or missing token.', 400);
        }

        $subscription = EmailSubscription::query()
            ->where('unsubscribe_token', $token)
            ->where('list_key', 'marketing')
            ->first();

        if (! $subscription) {
            return $this->error('Token not found or expired.', 404);
        }

        return $this->success([
            'email' => $subscription->email,
            'opted_in' => $subscription->status === 'subscribed',
            'status' => $subscription->status,
        ]);
    }

    /**
     * POST /api/public/unsubscribe/:token
     * One-shot unsubscribe — the token is rotated on success so the email link
     * cannot be replayed (e.g. by someone who finds the email later) to flip the
     * status. Re-subscribing requires the explicit opt-in path via
     * POST /api/public/subscribe, matching CASL/CAN-SPAM consent requirements.
     */
    public function unsubscribe(Request $request, string $token): JsonResponse
    {
        if (! $token || strlen($token) < 10) {
            return $this->error('Invalid unsubscribe token', 400);
        }

        $subscription = EmailSubscription::query()
            ->where('unsubscribe_token', $token)
            ->where('list_key', 'marketing')
            ->first();

        if (! $subscription) {
            return $this->error('Unsubscribe token not found or already processed', 404);
        }

        $subscription->markUnsubscribed();
        // Rotate so the original link is single-use.
        $subscription->unsubscribe_token = EmailSubscription::newUnsubscribeToken();
        $subscription->save();

        return $this->success([
            'message' => 'Successfully unsubscribed from marketing emails',
            'email' => $subscription->email,
        ]);
    }
}
