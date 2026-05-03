<?php

namespace App\Services\Shopify;

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use Illuminate\Support\Arr;

// Auto-fills professional, brand profile, and integration fields from Shopify shop data
// during OAuth onboarding, and handles on-demand resyncs.
//
// Resync semantics (Option B — Shopify as source of truth):
//   For each Shopify-sourced field, if Shopify returns a non-empty value, the local
//   value is overwritten. If Shopify returns empty or omits the field, the local
//   value is preserved. Manual edits in Sidest are NOT protected — a brand that
//   wants to keep a custom display_name should edit it in Shopify, not locally.
class ShopProfileAutoFillService
{
    /**
     * The 12 Shopify → Side St field mappings used by both signup auto-fill and resync.
     *
     * Each entry:
     *   - 'shopify' = key on the shop.json payload
     *   - 'target'  = one of 'professional', 'brand_profile', 'integration'
     *   - 'column'  = column (or metadata key, for integration target) on the target.
     *                  Also used as the logical field name in the resync response.
     */
    private const FIELD_MAP = [
        ['shopify' => 'name', 'target' => 'professional', 'column' => 'display_name'],
        ['shopify' => 'email', 'target' => 'professional', 'column' => 'primary_email'],
        ['shopify' => 'phone', 'target' => 'professional', 'column' => 'phone'],
        ['shopify' => 'address1', 'target' => 'professional', 'column' => 'location_street_address'],
        ['shopify' => 'city', 'target' => 'professional', 'column' => 'location_city'],
        ['shopify' => 'province', 'target' => 'professional', 'column' => 'location_state'],
        ['shopify' => 'zip', 'target' => 'professional', 'column' => 'location_postcode'],
        ['shopify' => 'country_name', 'target' => 'professional', 'column' => 'location_country'],
        ['shopify' => 'country_code', 'target' => 'professional', 'column' => 'country_code'],
        ['shopify' => 'iana_timezone', 'target' => 'professional', 'column' => 'timezone'],
        ['shopify' => 'domain', 'target' => 'brand_profile', 'column' => 'business_website'],
        ['shopify' => 'currency', 'target' => 'integration', 'column' => 'shop_currency'],
    ];

    /**
     * Fill Professional, Site, and BrandProfile fields from a Shopify shop object (signup path).
     * Only fills fields that are currently empty — never overwrites existing values.
     *
     * @param  array  $shopData  The `shop` object from Shopify's Admin API shop.json response
     */
    public function fillFromShopData(
        Professional $professional,
        Site $site,
        ?BrandProfile $brandProfile,
        array $shopData,
        ?ProfessionalIntegration $integration = null,
    ): void {
        $this->fillProfessional($professional, $shopData);
        $this->fillBrandProfile($brandProfile, $shopData);
        $this->fillIntegrationCurrency($integration, $shopData);

        $professional->save();

        if ($brandProfile !== null) {
            $brandProfile->save();
        }
    }

    /**
     * Resync Shopify-sourced fields. For each field in FIELD_MAP:
     *   - If Shopify returns a non-empty value → overwrite local.
     *   - If Shopify returns empty/missing → preserve local.
     *
     * No comparison against prior state; no "manual edit" detection.
     *
     * @return array{updated: string[], preserved: string[]}
     */
    public function resyncFromShopData(ProfessionalIntegration $integration, array $shopData): array
    {
        $professional = Professional::findOrFail($integration->professional_id);
        $brandProfile = BrandProfile::where('professional_id', $integration->professional_id)->first();

        $updated = [];
        $preserved = [];
        $professionalDirty = false;
        $brandProfileDirty = false;

        // Integration-target field writes (currently just shop_currency) go through
        // mergeProviderMetadata so sibling keys (webhook ids, storefront tokens, etc.)
        // written by concurrent jobs survive this update.
        $metadataMerge = [];

        foreach (self::FIELD_MAP as $field) {
            $freshValue = $this->freshValueForField($field, $shopData);

            if ($freshValue === '') {
                $preserved[] = $field['column'];

                continue;
            }

            $this->applyFreshValue($field, $freshValue, $professional, $brandProfile, $metadataMerge, $professionalDirty, $brandProfileDirty);
            $updated[] = $field['column'];
        }

        if ($professionalDirty) {
            $professional->save();
        }
        if ($brandProfileDirty && $brandProfile !== null) {
            $brandProfile->save();
        }

        if ($metadataMerge !== []) {
            $integration->mergeProviderMetadata($metadataMerge);
        }

        return [
            'updated' => $updated,
            'preserved' => $preserved,
        ];
    }

    private function fillProfessional(Professional $professional, array $shopData): void
    {
        $professional->display_name = $this->str($shopData, 'name') ?: $professional->display_name;
        $professional->first_name = $this->str($shopData, 'name') ?: $professional->first_name;
        $professional->primary_email = $this->str($shopData, 'email') ?: $professional->primary_email;
        $professional->phone = $this->str($shopData, 'phone') ?: $professional->phone;

        $professional->location_street_address = $this->str($shopData, 'address1') ?: $professional->location_street_address;
        $professional->location_city = $this->str($shopData, 'city') ?: $professional->location_city;
        $professional->location_state = $this->str($shopData, 'province') ?: $professional->location_state;
        $professional->location_postcode = $this->str($shopData, 'zip') ?: $professional->location_postcode;
        $professional->location_country = $this->str($shopData, 'country_name') ?: $professional->location_country;
        $professional->country_code = $this->str($shopData, 'country_code') ?: $professional->country_code;
        $professional->timezone = $this->str($shopData, 'iana_timezone') ?: $professional->timezone;
    }

    private function fillBrandProfile(?BrandProfile $brandProfile, array $shopData): void
    {
        if ($brandProfile === null) {
            return;
        }

        $domain = $this->str($shopData, 'domain');
        if ($domain !== '' && ($brandProfile->business_website === null || $brandProfile->business_website === '')) {
            $brandProfile->business_website = $domain;
        }
    }

    private function fillIntegrationCurrency(?ProfessionalIntegration $integration, array $shopData): void
    {
        if ($integration === null) {
            return;
        }

        $currency = strtoupper($this->str($shopData, 'currency'));
        if ($currency === '') {
            return;
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

        // Only set if not already recorded — same idempotency rule, but use atomic merge so we
        // don't clobber sibling keys written by concurrent onboarding jobs.
        if (($metadata['shop_currency'] ?? '') === '') {
            $integration->mergeProviderMetadata(['shop_currency' => $currency]);
        }
    }

    /**
     * Normalize a Shopify shop field to its stored form.
     * Country code and currency are upper-cased; everything else is trimmed as-is.
     */
    private function freshValueForField(array $field, array $shopData): string
    {
        $raw = $this->str($shopData, $field['shopify']);

        if (in_array($field['shopify'], ['currency', 'country_code'], true)) {
            return strtoupper($raw);
        }

        return $raw;
    }

    /**
     * Apply a non-empty fresh Shopify value to the correct target + mark the dirty flag
     * for batched save. For integration-target fields (currently just shop_currency),
     * the value is added to $metadataMerge which the caller passes to mergeProviderMetadata().
     */
    private function applyFreshValue(
        array $field,
        string $freshValue,
        Professional $professional,
        ?BrandProfile $brandProfile,
        array &$metadataMerge,
        bool &$professionalDirty,
        bool &$brandProfileDirty,
    ): void {
        if ($field['target'] === 'professional') {
            $professional->{$field['column']} = $freshValue;
            $professionalDirty = true;

            return;
        }

        if ($field['target'] === 'brand_profile') {
            if ($brandProfile === null) {
                return;
            }
            $brandProfile->{$field['column']} = $freshValue;
            $brandProfileDirty = true;

            return;
        }

        $metadataMerge[$field['column']] = $freshValue;
    }

    private function str(array $data, string $key): string
    {
        $value = Arr::get($data, $key);

        return is_string($value) ? trim($value) : '';
    }
}
