<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\Request;

class StaffMeController extends ApiController
{
    public function show(Request $request)
    {
        return $this->success([
            'uid' => $request->attributes->get('supabase_uid'),
            'staff' => $request->attributes->get('comet_staff'),
        ]);
    }
}
