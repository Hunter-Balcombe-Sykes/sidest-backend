<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;

// Centralises tenant resolution for the Partna embedded Shopify app surface.
// Reads `embedded_professional_id` (a UUID string) stashed on the request by
// VerifyShopifySessionToken AFTER full JWT signature + claim validation, loads
// the Professional, and caches it on the request so repeated calls in the same
// request don't re-query.
//
// Authorisation doctrine: this is the SOLE source of `$professional` for any
// embedded controller. Reading `professional_id` from a body / query / route
// parameter inside an embedded controller is a tenant-isolation bug —
// concentrating the read in one trait makes that rule auditable.
trait ResolveEmbeddedProfessional
{
    protected function currentEmbeddedProfessional(Request $request): Professional
    {
        $cached = $request->attributes->get('embedded_professional');
        if ($cached instanceof Professional) {
            return $cached;
        }

        $id = (string) $request->attributes->get('embedded_professional_id', '');
        if ($id === '') {
            // 401 — shopify.session middleware guarantees this attribute is
            // set. Missing it means the middleware was bypassed, stripped, or
            // a route that should require it was registered without it.
            abort(401, 'Embedded session did not resolve a professional. Ensure shopify.session middleware is applied.');
        }

        $pro = Professional::findOrFail($id);
        $request->attributes->set('embedded_professional', $pro);

        return $pro;
    }
}
