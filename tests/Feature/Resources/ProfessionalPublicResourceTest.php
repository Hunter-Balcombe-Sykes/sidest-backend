<?php

use App\Http\Resources\ProfessionalPublicResource;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;

it('returns brand_name and partna_url, no PII', function () {
    $pro = new Professional;
    $pro->setRawAttributes([
        'id' => 'pro-1',
        'handle' => 'evo',
        'handle_lc' => 'evo',
        'display_name' => 'Evo',
        'professional_type' => 'brand',
        'partna_url' => 'https://evo.partna.au',
        'bio' => 'Hair and beauty',
        'public_contact_number' => null,
        'public_contact_email' => 'shop@evo.example',
        'location_city' => 'Sydney',
        'location_state' => 'NSW',
        'location_country' => 'AU',
        'first_name' => 'SHOULD-NOT-LEAK',
        'last_name' => 'SHOULD-NOT-LEAK',
        'primary_email' => 'shouldnotleak@example.com',
    ]);

    $array = (new ProfessionalPublicResource($pro))->toArray(Request::create('/'));

    expect($array)
        ->toHaveKey('brand_name', 'Evo')
        ->toHaveKey('partna_url', 'https://evo.partna.au')
        ->not->toHaveKey('handle')
        ->not->toHaveKey('display_name')
        ->not->toHaveKey('first_name')
        ->not->toHaveKey('last_name')
        ->not->toHaveKey('primary_email');
});

it('returns username for non-brand professionals', function () {
    $pro = new Professional;
    $pro->setRawAttributes([
        'id' => 'pro-2',
        'handle' => 'barber-josh',
        'handle_lc' => 'barber-josh',
        'display_name' => 'Barber Josh',
        'professional_type' => 'influencer',
        'partna_url' => 'https://barber-josh.partna.au',
    ]);

    $array = (new ProfessionalPublicResource($pro))->toArray(Request::create('/'));

    expect($array)
        ->toHaveKey('username', 'Barber Josh')
        ->not->toHaveKey('brand_name');
});
