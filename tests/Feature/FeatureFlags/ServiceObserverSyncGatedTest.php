<?php

use App\Models\Core\Professional\Professional;
use App\Observers\Core\ServiceObserver;

function invokePrivateObserverMethod(string $method, Professional $pro): bool
{
    $observer = app(ServiceObserver::class);
    $reflection = new ReflectionClass($observer);
    $reflectionMethod = $reflection->getMethod($method);
    $reflectionMethod->setAccessible(true);

    return (bool) $reflectionMethod->invoke($observer, $pro);
}

it('shouldDispatchSquareSync returns false when square_sync flag is off', function () {
    config()->set('sidest.features.square_sync', false);

    $pro = new Professional(['id' => 'pro-anything']);

    expect(invokePrivateObserverMethod('shouldDispatchSquareSync', $pro))->toBeFalse();
});

it('shouldDispatchFreshaSync returns false when fresha_sync flag is off', function () {
    config()->set('sidest.features.fresha_sync', false);

    $pro = new Professional(['id' => 'pro-anything']);

    expect(invokePrivateObserverMethod('shouldDispatchFreshaSync', $pro))->toBeFalse();
});
