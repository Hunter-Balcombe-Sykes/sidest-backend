<?php

use App\Services\Diagnostics\EnvCheckService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

/*
 * Populate every config path the command checks with a placeholder value
 * before each test, so individual tests only need to clear the specific
 * keys they want to assert on. Framework-resolved driver keys (cache,
 * queue, session) need real driver names because providers eager-resolve
 * them during Artisan::call boot — a junk value like "set" would explode.
 */
function placeholderFor(string $path): string
{
    return match ($path) {
        'cache.default' => 'array',
        'queue.default' => 'sync',
        'session.driver' => 'array',
        default => 'set',
    };
}

beforeEach(function () {
    foreach (EnvCheckService::REQUIRED as $group => $entries) {
        foreach ($entries as $path => $envLabel) {
            Config::set($path, placeholderFor($path));
        }
    }
    foreach (EnvCheckService::RECOMMENDED as $group => $entries) {
        foreach ($entries as $path => $envLabel) {
            Config::set($path, placeholderFor($path));
        }
    }
});

it('exits 0 when all required and recommended config is set', function () {
    $exit = Artisan::call('env:check');

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('OK');
});

it('exits 1 when a required config key is missing', function () {
    Config::set('services.shopify.api_key', null);

    $exit = Artisan::call('env:check');

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('services.shopify.api_key');
});

it('treats an empty string as missing', function () {
    Config::set('services.shopify.api_secret', '');

    $exit = Artisan::call('env:check');

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('services.shopify.api_secret');
});

it('exits 0 with a warning when only a recommended key is missing', function () {
    Config::set('nightwatch.token', null);

    $exit = Artisan::call('env:check');

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('nightwatch.token');
});

it('reports every missing required key, not just the first', function () {
    Config::set('services.shopify.api_key', null);
    Config::set('services.stripe.secret_key', null);

    $exit = Artisan::call('env:check');
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('services.shopify.api_key');
    expect($output)->toContain('services.stripe.secret_key');
});

it('outputs JSON when --json is passed', function () {
    Config::set('services.shopify.api_key', null);

    Artisan::call('env:check', ['--json' => true]);
    $output = trim(Artisan::output());

    $data = json_decode($output, true);

    expect($data)->toBeArray();
    expect($data)->toHaveKey('status');
    expect($data)->toHaveKey('required_missing');
    expect($data['status'])->toBe('fail');
    expect($data['required_missing'])->toContain('services.shopify.api_key');
});

it('reports status=ok in JSON when nothing required is missing', function () {
    Artisan::call('env:check', ['--json' => true]);
    $data = json_decode(trim(Artisan::output()), true);

    expect($data['status'])->toBe('ok');
    expect($data['required_missing'])->toBe([]);
});
