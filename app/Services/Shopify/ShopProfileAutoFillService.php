<?php

namespace App\Services\Shopify;

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Support\Arr;

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
    ): void {
        $this->fillProfessional($professional, $shopData);
        $this->fillBrandProfile($brandProfile, $shopData);

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

    private function str(array $data, string $key): string
    {
        $value = Arr::get($data, $key);

        return is_string($value) ? trim($value) : '';
    }
}
