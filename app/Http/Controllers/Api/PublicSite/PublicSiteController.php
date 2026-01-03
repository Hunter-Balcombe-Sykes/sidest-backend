<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PublicSite\PublicSiteShowRequest;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Models\Views\PublicSitePayload;
use Symfony\Component\HttpFoundation\Response;

class PublicSiteController extends Controller
{
    public function show(PublicSiteShowRequest $request): Response
    {
        $data = $request->validated();
        $subdomain = $data['subdomain'];

        $row = PublicSitePayload::query()
            ->whereRaw('lower(subdomain) = lower(?)', [$subdomain])
            ->first();

        // View only contains published sites; if not found, treat as 404
        if (!$row) {
            $alias = SiteSubdomainAlias::query()->whereRaw('lower(subdomain) = ?', [strtolower($subdomain)])->first();
            if ($alias) {
                $site = Site::find($alias->site_id);
                $payloadBySite = $site
                    ? PublicSitePayload::query()->where('site_id', $site->id)->first()
                    : null;

                if ($payloadBySite && $site) {
                    $host = $site->subdomain . '.' . config('comet.public_domain');
                    $url = $request->getScheme() . '://' . $host . $request->getRequestUri();
                    return redirect()->to($url, 301);
                }
            }

            return response()->json(['message' => 'Site not found.'], 404);
        }

        $payload = $row->payload ?? [];

        // Return the consistent JSON shape your mini-site expects
        return response()->json([
            'published' => true,
            'site' => $payload['site'] ?? null,
            'professional' => $payload['professional'] ?? null,
            'theme' => $payload['theme'] ?? null,
            'links' => $payload['links'] ?? ($payload['blocks'] ?? []),
            'sections' => $payload['sections'] ?? [],
            'blocks' => $payload['blocks'] ?? ($payload['links'] ?? []),
        ]);
    }
}
