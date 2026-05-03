<?php

namespace App\Http\Requests\Api\Professional;

use App\Http\Requests\BaseFormRequest;

// V2: Validates plan subscription changes — requires plan ID plus success/cancel URLs for free-to-paid transitions.
class UpdatePlanSubscriptionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', 'exists:plans,id'],
            'success_url' => ['sometimes', 'nullable', 'url', $this->allowedRedirectRule()],
            'cancel_url' => ['sometimes', 'nullable', 'url', $this->allowedRedirectRule()],
        ];
    }

    private function allowedRedirectRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (! $value) {
                return;
            }

            $host = parse_url($value, PHP_URL_HOST);
            $frontendHost = parse_url(config('app.frontend_url', ''), PHP_URL_HOST);
            $appHost = parse_url(config('app.url', ''), PHP_URL_HOST);

            $allowed = array_filter([$frontendHost, $appHost, 'localhost', '127.0.0.1']);

            if (! $host || ! in_array($host, $allowed, true)) {
                $fail('The redirect URL domain is not allowed.');
            }
        };
    }
}
