<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject requests whose Supabase JWT does not have a confirmed email.
 *
 * Sits after `supabase.jwt` (which sets `supabase_claims`). The frontend
 * switches on the `error` code in the JSON body — HTTP status is 403 to
 * keep the contract uniform with other precondition gates (e.g. AAL2),
 * but the body distinguishes "you can't do this" from "you can't do this
 * YET, finish verification."
 *
 * NOT applied to /api/bootstrap on purpose: the frontend needs to call
 * bootstrap to discover account state (including verification status)
 * and render the "enter your code" screen.
 */
class RequireEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $claims = $request->attributes->get('supabase_claims');

        if (! is_array($claims)) {
            // If we got here without claims, supabase.jwt didn't run or failed
            // open — treat as unauthenticated rather than silently allowing.
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        // Supabase puts email_verified in both the JWT root and inside
        // user_metadata, but which one is populated depends on project age
        // + provider. Legacy projects (incl. this one's email/password users)
        // only have user_metadata.email_verified; modern asymmetric-JWT
        // setups duplicate it at the root. Check both so we don't 403 users
        // whose Supabase row clearly shows email_confirmed_at set.
        $userMetadata = is_array($claims['user_metadata'] ?? null) ? $claims['user_metadata'] : [];
        $emailVerified = (bool) ($claims['email_verified'] ?? $userMetadata['email_verified'] ?? false);

        if (! $emailVerified) {
            return response()->json([
                'error' => 'email_verification_required',
                'message' => 'Verify your email to continue.',
                'email' => $claims['email'] ?? null,
            ], 403);
        }

        return $next($request);
    }
}
