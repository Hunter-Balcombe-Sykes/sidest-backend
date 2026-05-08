<?php

use App\Models\Core\Professional\Professional;

it('does not allow mass-assigning partna_url', function () {
    $pro = new Professional;
    $pro->fill(['partna_url' => 'https://attacker.example.com']);

    expect($pro->partna_url)->toBeNull();
});
