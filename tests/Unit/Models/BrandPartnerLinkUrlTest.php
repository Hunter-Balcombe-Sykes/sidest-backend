<?php

use App\Models\Core\Professional\BrandPartnerLink;

it('does not allow mass-assigning site_url', function () {
    $link = new BrandPartnerLink;
    $link->fill(['site_url' => 'https://attacker.example.com']);

    expect($link->site_url)->toBeNull();
});

it('exposes site_url as a readable attribute', function () {
    $link = new BrandPartnerLink;
    $link->setRawAttributes(['site_url' => 'https://evo.partna.au/josh']);

    expect($link->site_url)->toBe('https://evo.partna.au/josh');
});
