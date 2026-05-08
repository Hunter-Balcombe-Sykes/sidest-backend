<?php

use App\Http\Resources\ProfessionalResource;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;

function buildPro(array $overrides = []): Professional
{
    $pro = new Professional;
    $pro->setRawAttributes(array_merge([
        'id' => 'pro-1',
        'handle' => 'evo',
        'handle_lc' => 'evo',
        'display_name' => 'Evo',
        'professional_type' => 'brand',
        'partna_url' => 'https://evo.partna.au',
        'first_name' => null,
        'last_name' => null,
        'bio' => null,
        'about' => null,
        'phone' => null,
        'primary_email' => 'evo@example.com',
        'country_code' => 'AU',
        'timezone' => 'Australia/Sydney',
        'status' => 'active',
        'onboarding_step' => 0,
        'public_contact_number' => null,
        'public_contact_email' => null,
        'location_street_address' => null,
        'location_city' => null,
        'location_state' => null,
        'location_postcode' => null,
        'location_country' => null,
        'stripe_connect_status' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $pro;
}

it('returns brand_name for brand-type professionals', function () {
    $pro = buildPro(['professional_type' => 'brand', 'display_name' => 'Push Pull']);
    $array = (new ProfessionalResource($pro))->toArray(Request::create('/'));

    expect($array)
        ->toHaveKey('brand_name', 'Push Pull')
        ->not->toHaveKey('username')
        ->toHaveKey('partna_url', $pro->partna_url);
});

it('returns username for non-brand professionals', function () {
    $pro = buildPro(['professional_type' => 'influencer', 'display_name' => 'Barber Josh']);
    $array = (new ProfessionalResource($pro))->toArray(Request::create('/'));

    expect($array)
        ->toHaveKey('username', 'Barber Josh')
        ->not->toHaveKey('brand_name');
});

it('does not expose handle or internal fields', function () {
    $pro = buildPro();
    $array = (new ProfessionalResource($pro))->toArray(Request::create('/'));

    expect($array)
        ->not->toHaveKey('handle')
        ->not->toHaveKey('handle_lc')
        ->not->toHaveKey('display_name');
});
