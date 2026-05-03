<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\Site\UpsertGoogleBusinessProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Store and retrieve Google Business Profile settings (place ID, hours, location, contact info).
class ProfessionalGoogleBusinessProfileController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    private const SETTINGS_KEY = 'google_business_profile';

    public function show(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);
        $settings = is_array($site->settings) ? $site->settings : [];

        return $this->success([
            'google_business_profile' => $this->normalizeProfile($settings[self::SETTINGS_KEY] ?? null),
        ]);
    }

    public function upsert(UpsertGoogleBusinessProfileRequest $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);
        $data = $request->validated();

        $profile = [
            'place_id' => (string) $data['place_id'],
            'name' => (string) $data['name'],
            'address' => $this->trimOrNull($data['address'] ?? null),
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'phone' => $this->trimOrNull($data['phone'] ?? null),
            'website' => $this->trimOrNull($data['website'] ?? null),
            'hours' => array_values(array_filter($data['hours'] ?? [], function ($value) {
                return is_string($value) && trim($value) !== '';
            })),
        ];

        $settings = is_array($site->settings) ? $site->settings : [];
        $settings[self::SETTINGS_KEY] = $profile;
        $site->settings = $settings;
        $site->save();

        return $this->success([
            'google_business_profile' => $this->normalizeProfile($settings[self::SETTINGS_KEY] ?? null),
        ]);
    }

    private function trimOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeProfile(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $placeId = $this->trimOrNull($raw['place_id'] ?? null);
        $name = $this->trimOrNull($raw['name'] ?? null);
        if (! $placeId || ! $name) {
            return null;
        }

        $hours = [];
        if (isset($raw['hours']) && is_array($raw['hours'])) {
            $hours = array_values(array_filter($raw['hours'], function ($value) {
                return is_string($value) && trim($value) !== '';
            }));
        }

        return [
            'place_id' => $placeId,
            'name' => $name,
            'address' => $this->trimOrNull($raw['address'] ?? null),
            'latitude' => is_numeric($raw['latitude'] ?? null) ? (float) $raw['latitude'] : null,
            'longitude' => is_numeric($raw['longitude'] ?? null) ? (float) $raw['longitude'] : null,
            'phone' => $this->trimOrNull($raw['phone'] ?? null),
            'website' => $this->trimOrNull($raw['website'] ?? null),
            'hours' => $hours,
        ];
    }
}
