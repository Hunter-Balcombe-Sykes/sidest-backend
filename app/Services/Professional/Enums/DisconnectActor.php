<?php

namespace App\Services\Professional\Enums;

// Who initiated a brand-affiliate link disconnect.
// Staff may void pending commissions; Brand and Affiliate always keep.
enum DisconnectActor: string
{
    case Staff = 'staff';
    case Brand = 'brand';
    case Affiliate = 'affiliate';
}
