<?php

namespace App\Http\Requests\Api\PublicSite\Analytics;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ResolvesPublicSiteSubdomain;
use Illuminate\Validation\Rule;

// Phase 5 analytics — public ingest for section-seen events fired by the storefront
// IntersectionObserver. Identifies the site by UUID or subdomain (same pattern as
// PageviewRequest), plus a section_key (required) and optional block_id when the
// section maps to a site.blocks row.
class SectionSeenRequest extends BaseFormRequest
{
    use ResolvesPublicSiteSubdomain;

    protected function prepareForValidation(): void
    {
        $this->mergeSubdomainFromRoute('X-Site-Subdomain');
    }

    public function rules(): array
    {
        return [
            'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
            'subdomain' => ['required_without:site_id', 'string', 'max:63'],
            // section_key is the canonical identifier — stable across content edits.
            // 64 char cap is generous; in practice keys are like "hero", "products", "about_me".
            'section_key' => ['required', 'string', 'max:64'],
            // block_id is optional — sections without a 1:1 Block row (header, footer, custom HTML)
            // still get tracked under section_key alone.
            'block_id' => ['nullable', 'uuid'],
            'session_id' => ['nullable', 'uuid'],
            'visitor_id' => ['nullable', 'uuid'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
        ];
    }
}
