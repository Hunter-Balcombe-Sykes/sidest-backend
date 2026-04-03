<?php

namespace App\Http\Middleware\Auth;

use App\Models\Core\Staff\CometStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// V2: Admin-only gate. Requires staff record with role=admin.
class EnsureCometAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $uid = $request->attributes->get('supabase_uid');

        if (!$uid) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $staff = $request->attributes->get('comet_staff');

        // If EnsureCometStaff ran before this, we already have the staff record
        if (!$staff) {
            $staff = CometStaff::query()->where('auth_user_id', $uid)->first();
        }

        if (!$staff || $staff->role !== 'admin') {
            return response()->json(['message' => 'Admin access required'], 403);
        }

        $request->attributes->set('comet_staff', $staff);

        return $next($request);
    }
}
