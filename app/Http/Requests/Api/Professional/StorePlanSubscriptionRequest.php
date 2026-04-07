<?php

namespace App\Http\Requests\Api\Professional;

use App\Http\Requests\BaseFormRequest;
use App\Models\Billing\Plan;
use Illuminate\Support\Facades\Cache;

// V2: Validates new plan subscription creation — requires plan ID plus success/cancel URLs for paid plans.
class StorePlanSubscriptionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $freePlanId = $this->freePlanId();

        return [
            'plan_id' => ['required', 'string', 'exists:plans,id'],
            'success_url' => ['required_unless:plan_id,' . $freePlanId, 'nullable', 'url', $this->allowedRedirectRule()],
            'cancel_url' => ['required_unless:plan_id,' . $freePlanId, 'nullable', 'url', $this->allowedRedirectRule()],
        ];
    }

    private function freePlanId(): string
    {
        return Cache::remember('billing.free_plan_id', 3600, function () {
            return Plan::where('plan_key', 'free')->value('id') ?? '';
        });
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
