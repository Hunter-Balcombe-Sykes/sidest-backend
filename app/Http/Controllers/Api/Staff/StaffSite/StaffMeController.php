<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StaffMeController extends Controller
{
    public function show(Request $request)
    {
        return response()->json([
            'uid' => $request->attributes->get('supabase_uid'),
            'staff' => $request->attributes->get('comet_staff'),
        ]);
    }
}
