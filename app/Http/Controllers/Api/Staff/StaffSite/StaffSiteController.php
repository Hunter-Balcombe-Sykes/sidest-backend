<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Views\AllSiteData;
use Illuminate\Http\JsonResponse;

class StaffSiteController extends ApiController
{
    public function show(string $subdomain): JsonResponse
    {
        $row = AllSiteData::query()
            ->whereRaw('lower(subdomain) = lower(?)', [$subdomain])
            ->first();

        if (!$row) {
            return $this->error('Site not found.', 404);
        }

        // Staff can see unpublished too, so we return published flag either way
        return $this->success([
            'is_published' => (bool) $row->is_published,

            'site' => [
                'id'        => $row->site_id,
                'subdomain' => $row->subdomain,
                'settings'  => $row->site_settings,
            ],

            'professional' => [
                'id'           => $row->professional_id,
                'handle'       => $row->professional_handle,
                'display_name' => $row->professional_display_name,
                'bio'          => $row->professional_bio,
                'icon_bucket'  => $row->professional_icon_bucket,
                'icon_path'    => $row->professional_icon_path,
                'headshot_bucket' => $row->professional_headshot_bucket,
                'headshot_path'   => $row->professional_headshot_path,
                'location_street_address' => $row->professional_location_street_address,
                'location_city' => $row->professional_location_city,
                'location_state' => $row->professional_location_state,
                'location_postcode' => $row->professional_location_postcode,
                'location_country' => $row->professional_location_country,
            ],

            'theme' => [
                'id'     => $row->theme_id,
                'key'    => $row->theme_key,
                'name'   => $row->theme_name,
                'config' => $row->theme_config,
            ],

            'blocks' => $row->blocks ?? [],
        ]);
    }

    public function showByProfessional(Professional $professional): JsonResponse
    {
        $row = AllSiteData::query()
            ->where('professional_id', $professional->id)
            ->first();

        if (!$row) {
            return $this->error('Site not found for professional.', 404);
        }

        return $this->success([
            'is_published' => (bool) $row->is_published,

            'site' => [
                'id'        => $row->site_id,
                'subdomain' => $row->subdomain,
                'settings'  => $row->site_settings,
            ],

            'professional' => [
                'id'           => $row->professional_id,
                'handle'       => $row->professional_handle,
                'display_name' => $row->professional_display_name,
                'bio'          => $row->professional_bio,
                'icon_bucket'  => $row->professional_icon_bucket,
                'icon_path'    => $row->professional_icon_path,
                'headshot_bucket' => $row->professional_headshot_bucket,
                'headshot_path'   => $row->professional_headshot_path,
                'location_street_address' => $row->professional_location_street_address,
                'location_city' => $row->professional_location_city,
                'location_state' => $row->professional_location_state,
                'location_postcode' => $row->professional_location_postcode,
                'location_country' => $row->professional_location_country,
            ],

            'theme' => [
                'id'     => $row->theme_id,
                'key'    => $row->theme_key,
                'name'   => $row->theme_name,
                'config' => $row->theme_config,
            ],

            'blocks' => $row->blocks ?? [],
        ]);
    }
}
