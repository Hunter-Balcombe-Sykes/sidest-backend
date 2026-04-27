<?php

use App\Http\Requests\BaseFormRequest;

function makeEmailRequest(array $data): BaseFormRequest
{
    $request = new class extends BaseFormRequest {
        public function rules(): array { return []; }
        public function exposeLowercaseEmails(array $keys): void { $this->lowercaseEmails($keys); }
    };
    $request->merge($data);
    return $request;
}

it('lowercases and trims', function () {
    $r = makeEmailRequest(['email' => '  Foo@Bar.COM  ']);
    $r->exposeLowercaseEmails(['email']);
    expect($r->input('email'))->toBe('foo@bar.com');
});

it('coerces empty strings to null', function () {
    $r = makeEmailRequest(['email' => '   ']);
    $r->exposeLowercaseEmails(['email']);
    expect($r->input('email'))->toBeNull();
});

it('leaves non-string values unchanged', function () {
    $r = makeEmailRequest(['email' => 0, 'other' => false]);
    $r->exposeLowercaseEmails(['email', 'other']);
    expect($r->input('email'))->toBe(0);
    expect($r->input('other'))->toBeFalse();
});

it('skips keys that are not present', function () {
    $r = makeEmailRequest([]);
    $r->exposeLowercaseEmails(['email']);
    expect($r->has('email'))->toBeFalse();
});
