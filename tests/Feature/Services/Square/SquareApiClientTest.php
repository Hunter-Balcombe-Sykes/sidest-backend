<?php

use App\Models\Core\Professional\Professional;
use App\Services\Square\SquareApiClient;
use App\Services\Square\SquareApiException;
use App\Services\Square\SquareTokenService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->mock(SquareTokenService::class, function ($mock) {
        $mock->shouldReceive('getAccessToken')->andReturn('test-token');
    });
    $this->client = app(SquareApiClient::class);
    $this->professional = Mockery::mock(Professional::class);
});

it('retries once after a 429 with Retry-After and succeeds', function () {
    Http::fakeSequence('*')
        ->push('{}', 429, ['Retry-After' => '1'])
        ->push('{"objects":[]}', 200);

    $result = $this->client->request($this->professional, 'GET', '/v2/catalog/list');

    expect($result)->toBeArray();
    Http::assertSentCount(2);
});

it('throws SquareApiException when 429 retries are exhausted', function () {
    Http::fake(['*' => Http::response('{}', 429, ['Retry-After' => '1'])]);

    expect(fn () => $this->client->request($this->professional, 'GET', '/v2/catalog/list'))
        ->toThrow(SquareApiException::class);
});
