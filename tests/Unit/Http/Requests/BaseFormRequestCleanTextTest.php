<?php

use App\Http\Requests\BaseFormRequest;

function makeCleanTextRequest(array $data): BaseFormRequest
{
    $request = new class extends BaseFormRequest {
        public function rules(): array { return []; }
        public function exposeCleanText(array $keys): void { $this->cleanText($keys); }
    };
    $request->merge($data);
    return $request;
}

it('strips HTML tags and trims whitespace', function () {
    $r = makeCleanTextRequest(['title' => '  <script>alert(1)</script>Hello  ']);
    $r->exposeCleanText(['title']);
    expect($r->input('title'))->toBe('Hello');
});

it('coerces empty results to null', function () {
    $r = makeCleanTextRequest(['title' => '<b></b>   ']);
    $r->exposeCleanText(['title']);
    expect($r->input('title'))->toBeNull();
});

it('strips ASCII control characters', function () {
    $r = makeCleanTextRequest(['title' => "Hello\x00\x07World\x7F"]);
    $r->exposeCleanText(['title']);
    expect($r->input('title'))->toBe('HelloWorld');
});

it('leaves non-string values unchanged', function () {
    $r = makeCleanTextRequest(['title' => 42, 'other' => null]);
    $r->exposeCleanText(['title', 'other']);
    expect($r->input('title'))->toBe(42);
    expect($r->input('other'))->toBeNull();
});

it('skips keys that are not present', function () {
    $r = makeCleanTextRequest([]);
    $r->exposeCleanText(['title']);
    expect($r->has('title'))->toBeFalse();
});

it('handles multiple keys in one call', function () {
    $r = makeCleanTextRequest(['title' => ' a ', 'bio' => '<p>b</p>']);
    $r->exposeCleanText(['title', 'bio']);
    expect($r->input('title'))->toBe('a');
    expect($r->input('bio'))->toBe('b');
});
