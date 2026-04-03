<?php

namespace App\Http\Middleware\Auth;

use App\Models\Core\Staff\SidestStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// V2: Admin-only gate. Requires staff record with role=admin.
class EnsureSidestAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $uid = $request->attributes->get('supabase_uid');

        if (!$uid) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $staff = $request->attributes->get('sidest_staff');

        // If EnsureSidestStaff ran before this, we already have the staff record
        if (!$staff) {
            $staff = SidestStaff::query()->where('auth_user_id', $uid)->first();
        }

        if (!$staff || $staff->role !== 'admin') {
            return response()->json(['message' => 'Admin access required'], 403);
        }

        $request->attributes->set('sidest_staff', $staff);

        return $next($request);
    }
}
