<?php

namespace App\Services\Shopify;

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use Illuminate\Support\Arr;

// V2: Auto-fills professional, brand profile, and integration fields from Shopify shop data during OAuth onboarding.
class ShopProfileAutoFillService
{
    /**
     * Fill Professional, Site, and BrandProfile fields from a Shopify shop object.
     *
     * @param array $shopData The `shop` object from Shopify's Admin API shop.json response
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

        if (($metadata['shop_currency'] ?? '') === '') {
            $metadata['shop_currency'] = $currency;
            $integration->provider_metadata = $metadata;
            $integration->save();
        }
    }

    private function str(array $data, string $key): string
    {
        $value = Arr::get($data, $key);

        return is_string($value) ? trim($value) : '';
    }
}
