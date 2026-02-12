<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Http\Requests\Api\PublicSite\CustomerLeads\PublicCustomerLeadRequest;
use App\Models\Analytics\LeadSubmission;
use App\Models\Core\Notifications\EmailSubscription;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\PublicSiteResolver;

class PublicCustomerLeadController extends ApiController
{
    use HashesClientData;
    use ResolvesSubdomainFromHost;

    public function store(PublicCustomerLeadRequest $request, PublicSiteResolver $resolver): JsonResponse
    {
        $data = $request->validated();
        $marketingOptIn = (bool)($data['marketing_opt_in'] ?? false);

        $subdomain = $this->resolveSubdomainFromHost($request);
        $subdomain = $subdomain ? strtolower($subdomain) : null;

        // 1) Honeypot: if filled, pretend success but do nothing
        $honeypot = $data['website'] ?? null;
        if (is_string($honeypot) && trim($honeypot) !== '') {
            $this->logLead($request, $subdomain, null, null, null, 'honeypot', $data['form_started_at_ms'] ?? null);
            return $this->success(['ok' => true], 201);
        }

        // 2) Timing check (optional field, but enforced if provided)
        $startedMs = $data['form_started_at_ms'] ?? null;
        if (is_int($startedMs)) {
            $nowMs = (int)floor(microtime(true) * 1000);
            $delta = $nowMs - $startedMs;

            $minMs = 2500;                  // 2.5s minimum fill time
            $maxMs = 12 * 60 * 60 * 1000;   // 12h max (stale form)

            if ($delta < $minMs || $delta > $maxMs) {
                $this->logLead($request, $subdomain, null, null, null, 'too_fast', $startedMs);
                return $this->error('Invalid submission.', 422);
            }
        }

        if (!$subdomain) {
            $this->logLead($request, null, null, null, null, 'no_subdomain', $startedMs);
            return $this->error('Could not determine site from URL.', 400);
        }

        $site = $resolver->resolvePublishedSite($subdomain);

        if (!$site) {
            $this->logLead($request, $subdomain, null, null, null, 'site_not_found', $startedMs);
            return $this->error('Site not found.', 404);
        }

        if (!$site->professional_id) {
            $this->logLead($request, $subdomain, $site->id, null, null, 'site_unlinked', $startedMs);
            return $this->error('Site is not linked to a professional.', 422);
        }

        $site->loadMissing('professional');

        if (!$site->professional) {
            $this->logLead($request, $subdomain, $site->id, null, null, 'site_unlinked', $startedMs);
            return $this->error('Site is not linked to a professional.', 422);
        }

        $pro = $site->professional;

        // Check if customer with this email already exists (excluding soft-deleted)
        $customer = $pro->customers()
            ->where('email', $data['email'])
            ->first();

        if ($customer) {
            // Update existing customer with new data
            $customer->update([
                'full_name' => $data['full_name'],
                'phone' => $data['phone'] ?? $customer->phone,
                'notes' => $data['notes'] ?? $customer->notes,
                // Don't overwrite external_id if already set
                'external_id' => $data['external_id'] ?? $customer->external_id,
            ]);
        } else {
            // Create new customer
            $customer = $pro->customers()->create([
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'notes' => $data['notes'] ?? null,
                'external_id' => $data['external_id'] ?? null,
                'source' => 'site',
            ]);
        }

        if ($marketingOptIn && !empty($data['email'])) {
            $this->upsertMarketingSubscription(
                professionalId: $pro->id,
                email: (string) $data['email'],
                fullName: $data['full_name'] ?? null,
                request: $request
            );
        }

        $this->logLead($request, $subdomain, $site->id, $pro->id, $customer->id, 'created', $startedMs);
        return $this->success([
            'ok' => true,
            'customer_id' => $customer->id,
        ], 201);
    }

    private function logLead(
        Request $request,
        ?string $subdomain,
        ?string $siteId,
        ?string $professionalId,
        ?string $customerId,
        string  $outcome,
        ?int    $formStartedAtMs
    ): void
    {
        LeadSubmission::query()->create([
            'occurred_at' => now(),
            'subdomain' => $subdomain,
            'site_id' => $siteId,
            'professional_id' => $professionalId,
            'customer_id' => $customerId,
            'ip_hash' => $this->hashIp($request->ip()),
            'user_agent' => $request->userAgent(),
            'referrer' => $request->headers->get('referer'),
            'outcome' => $outcome,
            'form_started_at_ms' => $formStartedAtMs,
        ]);
    }

    private function upsertMarketingSubscription(string $professionalId, string $email, ?string $fullName, Request $request): void
    {
        $email = strtolower(trim($email));
        if ($email === '') return;

        $listKey = 'marketing';

        try {
            $sub = EmailSubscription::query()
                ->where('professional_id', $professionalId)
                ->where('list_key', $listKey)
                ->where('email_lc', $email)
                ->first();

            if (!$sub) {
                $sub = new EmailSubscription([
                    'professional_id' => $professionalId,
                    'list_key' => $listKey,
                    'email' => $email,
                    'email_lc' => $email,
                    'full_name' => $fullName,
                    'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
                ]);
            } else {
                if ($fullName) {
                    $sub->full_name = $fullName;
                }
                if (!$sub->unsubscribe_token) {
                    $sub->unsubscribe_token = EmailSubscription::newUnsubscribeToken();
                }
            }

            $sub->markSubscribed([
                'source' => 'site_lead',
                'ip_hash' => $this->hashIp($request->ip()),
                'user_agent' => $request->userAgent(),
            ]);

            $sub->save();
        } catch (QueryException $e) {
            // If a race creates the row first, just ignore.
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }
    }


}
