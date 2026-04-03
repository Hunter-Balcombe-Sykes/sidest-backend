<?php

namespace App\Http\Requests\Api\PublicSite\Analytics;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class ClickRequest extends BaseFormRequest
{


    protected function prepareForValidation(): void
    {
        $routeSubdomain = $this->route('subdomain');
        $headerSubdomain = $this->header('X-Site-Subdomain');
        $candidateSubdomain = is_string($routeSubdomain) && $routeSubdomain !== ''
            ? $routeSubdomain
            : (is_string($headerSubdomain) ? trim($headerSubdomain) : '');

        if ($candidateSubdomain !== '') {
            $this->merge([
                'subdomain' => strtolower($candidateSubdomain),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'block_id' => ['required', 'uuid', Rule::exists('blocks', 'id')],
            'site_id'       => ['required_without:subdomain', 'uuid', Rule::exists('sites', 'id')],
            'subdomain'     => ['required_without:site_id', 'string', 'max:63'],
            'session_id'    => ['nullable', 'uuid'],
            'visitor_id'    => ['nullable', 'uuid'],
            'referrer'      => ['nullable', 'string', 'max:2048'],
            'utm_source'    => ['nullable', 'string', 'max:255'],
            'utm_medium'    => ['nullable', 'string', 'max:255'],
            'utm_campaign'  => ['nullable', 'string', 'max:255'],
        ];
    }
}
