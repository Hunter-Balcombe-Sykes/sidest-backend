<?php

namespace App\Services\Shopify;

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use Illuminate\Support\Arr;

// V2: Auto-fills professional, brand profile, and integration fields from Shopify shop data during OAuth onboarding,
//     and handles on-demand resyncs that preserve manually edited fields via a stored snapshot.
class ShopProfileAutoFillService
{
    /**
     * The 12 Shopify → Side St field mappings used by both signup auto-fill and resync.
     *
     * Each entry:
     *   - 'shopify' = key on the shop.json payload
     *   - 'target'  = one of 'professional', 'brand_profile', 'integration'
     *   - 'column'  = column or metadata key on the target
     *   - 'snapshot_key' = the logical field name recorded in the snapshot + returned to callers
     *
     * Kept as a constant so fillFromShopData(), resyncFromShopData(), and the snapshot writer
     * stay in lockstep — the snapshot is only meaningful if it covers exactly what we auto-fill.
     */
    private const FIELD_MAP = [
        ['shopify' => 'name', 'target' => 'professional', 'column' => 'display_name', 'snapshot_key' => 'display_name'],
        ['shopify' => 'email', 'target' => 'professional', 'column' => 'primary_email', 'snapshot_key' => 'primary_email'],
        ['shopify' => 'phone', 'target' => 'professional', 'column' => 'phone', 'snapshot_key' => 'phone'],
        ['shopify' => 'address1', 'target' => 'professional', 'column' => 'location_street_address', 'snapshot_key' => 'location_street_address'],
        ['shopify' => 'city', 'target' => 'professional', 'column' => 'location_city', 'snapshot_key' => 'location_city'],
        ['shopify' => 'province', 'target' => 'professional', 'column' => 'location_state', 'snapshot_key' => 'location_state'],
        ['shopify' => 'zip', 'target' => 'professional', 'column' => 'location_postcode', 'snapshot_key' => 'location_postcode'],
        ['shopify' => 'country_name', 'target' => 'professional', 'column' => 'location_country', 'snapshot_key' => 'location_country'],
        ['shopify' => 'country_code', 'target' => 'professional', 'column' => 'country_code', 'snapshot_key' => 'country_code'],
        ['shopify' => 'iana_timezone', 'target' => 'professional', 'column' => 'timezone', 'snapshot_key' => 'timezone'],
        ['shopify' => 'domain', 'target' => 'brand_profile', 'column' => 'business_website', 'snapshot_key' => 'business_website'],
        ['shopify' => 'currency', 'target' => 'integration', 'column' => 'shop_currency', 'snapshot_key' => 'shop_currency'],
    ];

    /**
     * Fill Professional, Site, and BrandProfile fields from a Shopify shop object (signup path).
     * Writes a full snapshot of all 12 Shopify-sourced fields into the integration's provider_metadata
     * so future resyncs have a baseline to compare against.
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

        // Always record the snapshot at signup, even for fields we didn't apply (because they were
        // already populated). The snapshot is "what Shopify last told us" — a baseline for later diffs.
        if ($integration !== null) {
            $this->writeSnapshot($integration, $shopData);
        }
    }

    /**
     * Resync Shopify-sourced fields, preserving any values the user has manually edited.
     *
     * Comparison model: if the current DB value equals the last snapshot value, the field is still
     * considered Shopify-sourced and gets overwritten with the fresh value. If it differs, the user
     * has edited it and we leave it alone. Missing snapshot (legacy integrations) is treated conservatively:
     * any non-empty DB value is assumed user-edited and preserved.
     *
     * After field-level diffs, the snapshot is always overwritten with the fresh Shopify data so the
     * next resync has the latest baseline.
     *
     * @return array{updated: string[], preserved: string[]}
     */
    public function resyncFromShopData(ProfessionalIntegration $integration, array $shopData): array
    {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $previousSnapshot = is_array($metadata['shopify_shop_snapshot'] ?? null)
            ? $metadata['shopify_shop_snapshot']
            : null;

        $professional = Professional::findOrFail($integration->professional_id);
        $brandProfile = BrandProfile::where('professional_id', $integration->professional_id)->first();

        $updated = [];
        $preserved = [];
        $professionalDirty = false;
        $brandProfileDirty = false;

        // Keys that need to be merged back into provider_metadata at the end.
        // Only snapshot + (optionally) currency — all other sibling keys must
        // be preserved via the atomic jsonb merge in mergeProviderMetadata().
        $metadataMerge = [];

        foreach (self::FIELD_MAP as $field) {
            $freshValue = $this->freshValueForField($field, $shopData);
            $currentValue = $this->currentValueForField($field, $professional, $brandProfile, $metadata);
            $previousSnapshotValue = $previousSnapshot[$field['snapshot_key']] ?? null;

            // No snapshot baseline: be conservative. If the user has anything there, keep it.
            if ($previousSnapshot === null) {
                if ($currentValue !== null && $currentValue !== '') {
                    $preserved[] = $field['snapshot_key'];

                    continue;
                }
                // DB is empty — safe to fill from Shopify (if Shopify has a value).
                if ($freshValue === null || $freshValue === '') {
                    continue;
                }
                $this->applyFreshValue($field, $freshValue, $professional, $brandProfile, $metadataMerge, $professionalDirty, $brandProfileDirty);
                $updated[] = $field['snapshot_key'];

                continue;
            }

            // Snapshot exists: Shopify owns the field only if the DB hasn't diverged from what
            // Shopify previously told us.
            $userEdited = ! $this->valuesMatch($currentValue, $previousSnapshotValue);

            if ($userEdited) {
                $preserved[] = $field['snapshot_key'];

                continue;
            }

            // Shopify-owned field. Apply the new value even if empty — Shopify cleared it.
            $this->applyFreshValue($field, $freshValue, $professional, $brandProfile, $metadataMerge, $professionalDirty, $brandProfileDirty);
            $updated[] = $field['snapshot_key'];
        }

        // Batch one UPDATE per model.
        if ($professionalDirty) {
            $professional->save();
        }
        if ($brandProfileDirty && $brandProfile !== null) {
            $brandProfile->save();
        }

        // Write the fresh snapshot (+ any shop_currency change) atomically into provider_metadata.
        // mergeProviderMetadata uses a jsonb || merge on pgsql so sibling keys written by concurrent
        // jobs (webhook registration / storefront token / sales channel) survive even if they landed
        // during the Shopify API call window.
        $metadataMerge['shopify_shop_snapshot'] = $this->buildSnapshot($shopData);
        $integration->mergeProviderMetadata($metadataMerge);

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
     * Normalize a Shopify shop field to its stored form (what we'd persist into the DB).
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
     * Read the current value off whichever target the field lives on.
     */
    private function currentValueForField(array $field, Professional $professional, ?BrandProfile $brandProfile, array $metadata): ?string
    {
        if ($field['target'] === 'professional') {
            $value = $professional->{$field['column']};

            return $value === null ? null : (string) $value;
        }

        if ($field['target'] === 'brand_profile') {
            if ($brandProfile === null) {
                return null;
            }
            $value = $brandProfile->{$field['column']};

            return $value === null ? null : (string) $value;
        }

        // integration → provider_metadata['shop_currency']
        $value = $metadata[$field['column']] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * Apply a fresh Shopify value to the correct target + mark the dirty flag for batched save.
     * For integration-target fields (currently just shop_currency), the value is added to
     * $metadataMerge which the caller passes to mergeProviderMetadata() for atomic writing.
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
            $professional->{$field['column']} = $freshValue !== '' ? $freshValue : null;
            $professionalDirty = true;

            return;
        }

        if ($field['target'] === 'brand_profile') {
            if ($brandProfile === null) {
                return;
            }
            $brandProfile->{$field['column']} = $freshValue !== '' ? $freshValue : null;
            $brandProfileDirty = true;

            return;
        }

        // integration → delta goes into the merge payload
        $metadataMerge[$field['column']] = $freshValue !== '' ? $freshValue : null;
    }

    /**
     * Loose equality for comparing current DB values to previous snapshot values. Both sides are
     * coerced to trimmed strings; null and '' are treated as equivalent (both "no value").
     */
    private function valuesMatch(?string $current, mixed $previous): bool
    {
        $left = trim((string) ($current ?? ''));
        $right = is_scalar($previous) ? trim((string) $previous) : '';

        return $left === $right;
    }

    /**
     * Build a fresh snapshot of all 12 fields from raw Shopify shop data.
     * Values are normalized exactly the same way we'd persist them.
     *
     * @return array<string, string|null>
     */
    private function buildSnapshot(array $shopData): array
    {
        $snapshot = [];

        foreach (self::FIELD_MAP as $field) {
            $value = $this->freshValueForField($field, $shopData);
            $snapshot[$field['snapshot_key']] = $value === '' ? null : $value;
        }

        return $snapshot;
    }

    /**
     * Persist the snapshot into provider_metadata without clobbering sibling keys.
     * Uses the atomic jsonb merge so concurrent webhook / storefront / sales-channel
     * writes during signup cannot be overwritten.
     */
    private function writeSnapshot(ProfessionalIntegration $integration, array $shopData): void
    {
        $integration->mergeProviderMetadata([
            'shopify_shop_snapshot' => $this->buildSnapshot($shopData),
        ]);
    }

    private function str(array $data, string $key): string
    {
        $value = Arr::get($data, $key);

        return is_string($value) ? trim($value) : '';
    }
}
