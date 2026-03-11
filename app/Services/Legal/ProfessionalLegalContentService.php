<?php

namespace App\Services\Legal;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalLegalContent;
use App\Models\Core\Site\Site;

class ProfessionalLegalContentService
{
    public function getOrCreate(Professional $professional, ?Site $site = null): ProfessionalLegalContent
    {
        $legal = ProfessionalLegalContent::query()
            ->where('professional_id', $professional->id)
            ->first();

        if (!$legal) {
            return $this->refreshGenerated($professional, $site);
        }

        if (
            trim((string) $legal->generated_privacy_policy) === ''
            || trim((string) $legal->generated_terms_and_conditions) === ''
        ) {
            return $this->refreshGenerated($professional, $site);
        }

        return $legal;
    }

    public function refreshGenerated(Professional $professional, ?Site $site = null): ProfessionalLegalContent
    {
        $site = $site ?? $professional->site;

        if (!$site) {
            $professional->loadMissing('site');
            $site = $professional->site;
        }

        $variables = $this->buildTemplateVariables($professional, $site);

        $generatedPrivacyPolicy = $this->renderTemplate(
            (string) config('comet.legal.templates.privacy_policy', ''),
            $variables
        );

        $generatedTermsAndConditions = $this->renderTemplate(
            (string) config('comet.legal.templates.terms_and_conditions', ''),
            $variables
        );

        $legal = ProfessionalLegalContent::firstOrNew([
            'professional_id' => $professional->id,
        ]);

        $seedManualFromGenerated = !$legal->exists || (
            trim((string) $legal->generated_privacy_policy) === ''
            && trim((string) $legal->generated_terms_and_conditions) === ''
        );

        if (!$legal->exists) {
            $legal->active_privacy_source = ProfessionalLegalContent::SOURCE_TEMPLATED;
            $legal->active_terms_source = ProfessionalLegalContent::SOURCE_TEMPLATED;
        }

        $legal->generated_privacy_policy = $generatedPrivacyPolicy;
        $legal->generated_terms_and_conditions = $generatedTermsAndConditions;

        // Seed manual drafts from templated content on first initialization only.
        if ($seedManualFromGenerated) {
            if ($this->trimOrNull($legal->manual_privacy_policy) === null) {
                $legal->manual_privacy_policy = $generatedPrivacyPolicy;
            }
            if ($this->trimOrNull($legal->manual_terms_and_conditions) === null) {
                $legal->manual_terms_and_conditions = $generatedTermsAndConditions;
            }
        }

        $legal->template_variables = $variables;
        $legal->generated_at = now();
        $legal->save();

        return $legal->fresh();
    }

    public function toApiPayload(ProfessionalLegalContent $legal): array
    {
        return [
            'generated_privacy_policy' => $legal->generated_privacy_policy,
            'manual_privacy_policy' => $legal->manual_privacy_policy,
            'active_privacy_source' => $legal->active_privacy_source,
            'active_privacy_policy' => $legal->resolveActivePrivacyPolicy(),
            'generated_terms_and_conditions' => $legal->generated_terms_and_conditions,
            'manual_terms_and_conditions' => $legal->manual_terms_and_conditions,
            'active_terms_source' => $legal->active_terms_source,
            'active_terms_and_conditions' => $legal->resolveActiveTermsAndConditions(),
            'template_variables' => is_array($legal->template_variables) ? $legal->template_variables : [],
            'generated_at' => optional($legal->generated_at)->toIso8601String(),
            'updated_at' => optional($legal->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildTemplateVariables(Professional $professional, ?Site $site): array
    {
        $fullName = trim(implode(' ', array_filter([
            $this->trimOrNull($professional->first_name ?? null),
            $this->trimOrNull($professional->last_name ?? null),
        ])));

        $legalName = $this->firstNonEmpty([
            $fullName,
            $this->trimOrNull($professional->display_name ?? null),
            $this->trimOrNull($professional->handle ?? null),
            'Professional',
        ]);

        $barbershopName = $this->firstNonEmpty([
            $this->trimOrNull($professional->display_name ?? null),
            $fullName,
            $this->trimOrNull($professional->handle ?? null),
            'Barbershop',
        ]);

        $contactName = $this->firstNonEmpty([
            $fullName,
            $this->trimOrNull($professional->display_name ?? null),
            $this->trimOrNull(config('comet.legal.defaults.contact_name')),
            'Customer Support',
        ]);

        $supportEmail = $this->firstNonEmpty([
            $this->trimOrNull($professional->public_contact_email ?? null),
            $this->trimOrNull($professional->primary_email ?? null),
            $this->trimOrNull(config('comet.legal.defaults.support_email')),
            'support@comet.app',
        ]);

        $supportPhone = $this->firstNonEmpty([
            $this->trimOrNull($professional->public_contact_number ?? null),
            $this->trimOrNull($professional->phone ?? null),
            $this->trimOrNull(config('comet.legal.defaults.support_phone')),
            'N/A',
        ]);

        return [
            'effective_date' => now()->toFormattedDateString(),
            'professional_legal_name' => $legalName,
            'barbershop_name' => $barbershopName,
            'site_url' => $this->resolveSiteUrl($professional, $site),
            'contact_name' => $contactName,
            'support_email' => $supportEmail,
            'support_phone' => $supportPhone,
        ];
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function renderTemplate(string $template, array $variables): string
    {
        $replacements = [];

        foreach ($variables as $key => $value) {
            $replacements['{{' . $key . '}}'] = $value;
        }

        return trim(strtr($template, $replacements));
    }

    private function resolveSiteUrl(Professional $professional, ?Site $site): string
    {
        $scheme = (string) config('comet.legal.site_scheme', 'https');
        $domain = (string) config('comet.public_domain');
        $subdomain = $this->trimOrNull($site?->subdomain);

        if ($subdomain && $domain !== '') {
            return $scheme . '://' . strtolower($subdomain) . '.' . $domain;
        }

        $handle = $this->trimOrNull($professional->handle ?? null);
        if ($handle && $domain !== '') {
            return $scheme . '://' . strtolower($handle) . '.' . $domain;
        }

        return (string) config('app.url', 'https://example.com');
    }

    /**
     * @param  array<int, string|null>  $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $trimmed = $this->trimOrNull($value);
            if ($trimmed !== null) {
                return $trimmed;
            }
        }

        return '';
    }

    private function trimOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
