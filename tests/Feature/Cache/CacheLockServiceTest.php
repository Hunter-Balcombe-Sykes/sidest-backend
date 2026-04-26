<?php

use App\Services\Cache\CacheLockService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Mockery as M;

beforeEach(function () {
    Cache::flush();
    $this->service = new CacheLockService;
});

afterEach(function () {
    M::close();
});

it('returns cached value without acquiring lock on cache hit', function () {
    Cache::shouldReceive('get')->with('test:key')->andReturn(['cached' => true])->once();
    Cache::shouldReceive('lock')->never();

    $result = $this->service->rememberLocked(
        'test:key',
        60,
        fn () => throw new RuntimeException('closure should not run'),
    );

    expect($result)->toBe(['cached' => true]);
});

it('runs closure and stores result on cache miss', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:miss')->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:test:miss', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->with('test:miss', ['fresh' => 'value'], 60)->once();

    $result = $this->service->rememberLocked(
        'test:miss',
        60,
        fn () => ['fresh' => 'value'],
    );

    expect($result)->toBe(['fresh' => 'value']);
});

it('skips closure when cache fills during lock wait (double-check)', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    // First Cache::get returns null (initial miss); second returns filled value
    // (another process filled it while we were waiting on the lock).
    Cache::shouldReceive('get')
        ->with('test:double')
        ->twice()
        ->andReturn(null, ['filled' => 'by other']);
    Cache::shouldReceive('lock')->with('lock:test:double', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never();

    $closureRan = false;
    $result = $this->service->rememberLocked(
        'test:double',
        60,
        function () use (&$closureRan) {
            $closureRan = true;

            return ['fresh' => 'should not run'];
        },
    );

    expect($result)->toBe(['filled' => 'by other']);
    expect($closureRan)->toBeFalse();
});

it('falls through to closure when lock acquisition times out and cache is still empty', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once()->andThrow(new LockTimeoutException);

    // Initial miss, then re-check after timeout still returns null.
    Cache::shouldReceive('get')->with('test:timeout')->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:test:timeout', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never();

    $result = $this->service->rememberLocked(
        'test:timeout',
        60,
        fn () => ['last' => 'resort'],
    );

    expect($result)->toBe(['last' => 'resort']);
});

it('returns cached value on lock timeout if cache filled in the meantime', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once()->andThrow(new LockTimeoutException);

    Cache::shouldReceive('get')
        ->with('test:timeout-filled')
        ->twice()
        ->andReturn(null, ['filled' => 'while waiting']);
    Cache::shouldReceive('lock')->with('lock:test:timeout-filled', 10)->once()->andReturn($lock);

    $closureRan = false;
    $result = $this->service->rememberLocked(
        'test:timeout-filled',
        60,
        function () use (&$closureRan) {
            $closureRan = true;

            return ['should' => 'not run'];
        },
    );

    expect($result)->toBe(['filled' => 'while waiting']);
    expect($closureRan)->toBeFalse();
});

it('releases lock when closure throws', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:throw')->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:test:throw', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never();

    $call = fn () => $this->service->rememberLocked(
        'test:throw',
        60,
        fn () => throw new RuntimeException('boom'),
    );

    expect($call)->toThrow(RuntimeException::class, 'boom');
});

it('honours custom lockSeconds and blockSeconds', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(2)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:custom')->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:test:custom', 30)->once()->andReturn($lock);
    Cache::shouldReceive('put')->with('test:custom', 'v', 60)->once();

    $result = $this->service->rememberLocked(
        'test:custom',
        60,
        fn () => 'v',
        lockSeconds: 30,
        blockSeconds: 2,
    );

    expect($result)->toBe('v');
});
