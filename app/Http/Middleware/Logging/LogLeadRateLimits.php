<?php

namespace App\Http\Middleware\Logging;

use App\Models\Analytics\LeadSubmission;
use App\Http\Controllers\Concerns\HashesClientData;
use Closure;
use Illuminate\Http\Request;

class LogLeadRateLimits
{
    use HashesClientData;
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($response->getStatusCode() === 429) {
            $subdomain = (string) ($request->route('subdomain') ?? explode('.', $request->getHost())[0] ?? 'unknown');

            LeadSubmission::query()->create([
                'occurred_at' => now(),
                'subdomain' => $subdomain !== '' ? strtolower($subdomain) : null,
                'ip_hash' => $this->hashIp($request->ip()),
                'user_agent' => $request->userAgent(),
                'referrer' => $request->headers->get('referer'),
                'outcome' => 'rate_limited',
                'form_started_at_ms' => null,
            ]);
        }

        return $response;
    }
}
