<?php

use App\Http\Requests\BaseFormRequest;

function makePhoneRequest(array $data): BaseFormRequest
{
    $request = new class extends BaseFormRequest {
        public function rules(): array { return []; }
        public function exposeNormalizePhones(array $keys): void { $this->normalizePhones($keys); }
    };
    $request->merge($data);
    return $request;
}

it('strips non-digit non-plus characters and leaves digits intact', function () {
    $r = makePhoneRequest(['phone' => '+1 (555) 123-4567']);
    $r->exposeNormalizePhones(['phone']);
    expect($r->input('phone'))->toBe('+15551234567');
});

it('coerces empty strings to null', function () {
    $r = makePhoneRequest(['phone' => '   ']);
    $r->exposeNormalizePhones(['phone']);
    expect($r->input('phone'))->toBeNull();
});

it('leaves non-string values unchanged', function () {
    $r = makePhoneRequest(['phone' => 12345, 'other' => null]);
    $r->exposeNormalizePhones(['phone', 'other']);
    expect($r->input('phone'))->toBe(12345);
    expect($r->input('other'))->toBeNull();
});

it('skips keys that are not present', function () {
    $r = makePhoneRequest([]);
    $r->exposeNormalizePhones(['phone']);
    expect($r->has('phone'))->toBeFalse();
});

it('handles multiple keys in one call', function () {
    $r = makePhoneRequest(['phone' => '555-1212', 'public_contact_number' => '+44 20 7946 0958']);
    $r->exposeNormalizePhones(['phone', 'public_contact_number']);
    expect($r->input('phone'))->toBe('5551212');
    expect($r->input('public_contact_number'))->toBe('+442079460958');
});
