<?php

namespace App\Http\Middleware\Auth;

use App\Models\Core\Staff\SidestStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// V2: Staff-only gate. Checks supabase_uid maps to a SidestStaff record.
// Accepts optional role parameters: middleware('staff:admin') restricts to a specific role,
// middleware('staff') allows any authenticated staff.
class EnsureSidestStaff
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Set by VerifySupabaseJwt middleware
        $uid = $request->attributes->get('supabase_uid');

        if (! $uid) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $staff = $request->attributes->get('sidest_staff')
            ?? SidestStaff::query()->where('auth_user_id', $uid)->first();

        if (! $staff) {
            return response()->json(['message' => 'Staff access required'], 403);
        }

        if (! empty($roles) && ! in_array($staff->role, $roles, true)) {
            return response()->json(['message' => 'Insufficient staff role'], 403);
        }

        // Attach for controllers to use
        $request->attributes->set('sidest_staff', $staff);

        return $next($request);
    }
}
