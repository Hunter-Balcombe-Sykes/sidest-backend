<?php

namespace App\Http\Middleware\Auth;

use App\Models\Core\Staff\CometStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// V2: Staff-only gate. Checks supabase_uid maps to a CometStaff record.
class EnsureCometStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        // Set by VerifySupabaseJwt middleware
        $uid = $request->attributes->get('supabase_uid');

        if (!$uid) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $staff = CometStaff::query()
            ->where('auth_user_id', $uid)
            ->first();

        if (!$staff) {
            return response()->json(['message' => 'Staff access required'], 403);
        }

        // Attach for controllers to use
        $request->attributes->set('comet_staff', $staff);

        return $next($request);
    }
}
