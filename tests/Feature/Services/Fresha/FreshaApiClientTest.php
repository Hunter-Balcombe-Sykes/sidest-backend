<?php

use App\Models\Core\Professional\Professional;
use App\Services\Fresha\FreshaApiClient;
use App\Services\Fresha\FreshaApiException;
use App\Services\Fresha\FreshaTokenService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->mock(FreshaTokenService::class, function ($mock) {
        $mock->shouldReceive('getAccessToken')->andReturn('test-token');
    });
    $this->client = app(FreshaApiClient::class);
    $this->professional = Mockery::mock(Professional::class);
});

it('retries once after a 429 with Retry-After and succeeds', function () {
    Http::fakeSequence('*')
        ->push('{}', 429, ['Retry-After' => '1'])
        ->push('{"data":[]}', 200);

    $result = $this->client->request($this->professional, 'GET', '/v1/businesses/test/services');

    expect($result)->toBeArray();
    Http::assertSentCount(2);
});

it('throws FreshaApiException when 429 retries are exhausted', function () {
    Http::fake(['*' => Http::response('{}', 429, ['Retry-After' => '1'])]);

    expect(fn () => $this->client->request($this->professional, 'GET', '/v1/businesses/test/services'))
        ->toThrow(FreshaApiException::class);
});
