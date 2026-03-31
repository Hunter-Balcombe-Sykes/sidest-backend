<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Requests\Api\PublicSite\PublicWaitlistSignupRequest;
use App\Models\Core\Waitlist\WaitlistSignup;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class PublicWaitlistController extends ApiController
{
    use HashesClientData;

    public function store(PublicWaitlistSignupRequest $request): JsonResponse
    {
        $data = $request->validated();
        $email = mb_strtolower(trim((string) $data['email']));
        $submittedAt = now();

        $payload = [
            'name' => $data['name'],
            'email' => $email,
            'email_lc' => $email,
            'phone' => $data['phone'],
            'applicant_type' => $data['type'],
            'applicant_type_other' => $data['type_other_text'] ?? null,
            'industry' => $data['industry'],
            'industry_other' => $data['industry_other_text'] ?? null,
            'pilot_program_opt_in' => (bool) $data['pilot_program_opt_in'],
            'number_of_team_members' => $data['number_of_team_members'] ?? null,
            'number_of_affiliates_ambassadors' => $data['number_of_affiliates_ambassadors'] ?? null,
            'is_brand_partner_or_ambassador' => $data['is_brand_partner_or_ambassador'] ?? null,
            'currently_sells_products' => $data['currently_sells_products'] ?? null,
            'consent_source' => 'waitlist_form',
            'consent_ip_hash' => $this->hashIp($request->ip()),
            'consent_user_agent' => $request->userAgent(),
            'last_submitted_at' => $submittedAt,
        ];

        $signup = $this->upsertWaitlistSignup($email, $payload);

        return $this->success(['ok' => true], $signup->wasRecentlyCreated ? 201 : 200);
    }

    private function upsertWaitlistSignup(string $emailLc, array $payload): WaitlistSignup
    {
        try {
            return WaitlistSignup::query()->updateOrCreate(
                ['email_lc' => $emailLc],
                $payload
            );
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = WaitlistSignup::query()
                ->where('email_lc', $emailLc)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            $existing->fill($payload);
            $existing->save();

            return $existing;
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();
        if ($code === '23505' || $code === '23000') {
            return true;
        }

        $message = mb_strtolower((string) $exception->getMessage());
        return str_contains($message, 'unique') && str_contains($message, 'email_lc');
    }
}
