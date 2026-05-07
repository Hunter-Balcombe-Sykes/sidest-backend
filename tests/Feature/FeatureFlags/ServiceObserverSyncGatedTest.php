<?php

use App\Jobs\Fresha\PushServiceToFreshaJob;
use App\Jobs\Square\PushServiceToSquareJob;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\Service;
use App\Observers\Core\ServiceObserver;
use Illuminate\Support\Facades\Queue;

function invokePrivateObserverMethod(string $method, Professional $pro): bool
{
    $observer = app(ServiceObserver::class);
    $reflection = new ReflectionClass($observer);
    $reflectionMethod = $reflection->getMethod($method);
    $reflectionMethod->setAccessible(true);

    return (bool) $reflectionMethod->invoke($observer, $pro);
}

function invokeDispatchMethod(string $method, string $serviceId, string $action): void
{
    $observer = app(ServiceObserver::class);
    $reflection = new ReflectionClass($observer);
    $reflectionMethod = $reflection->getMethod($method);
    $reflectionMethod->setAccessible(true);
    $reflectionMethod->invoke($observer, $serviceId, $action);
}

it('shouldDispatchSquareSync returns false when square_sync flag is off', function () {
    config()->set('partna.features.square_sync', false);

    $pro = new Professional(['id' => 'pro-anything']);

    expect(invokePrivateObserverMethod('shouldDispatchSquareSync', $pro))->toBeFalse();
});

it('shouldDispatchFreshaSync returns false when fresha_sync flag is off', function () {
    config()->set('partna.features.fresha_sync', false);

    $pro = new Professional(['id' => 'pro-anything']);

    expect(invokePrivateObserverMethod('shouldDispatchFreshaSync', $pro))->toBeFalse();
});

// CR-006: dispatch() queues jobs rather than blocking the HTTP response synchronously.
it('dispatchSquareSync queues PushServiceToSquareJob', function () {
    Queue::fake();

    invokeDispatchMethod('dispatchSquareSync', 'svc-123', 'upsert');

    Queue::assertPushed(PushServiceToSquareJob::class, function ($job) {
        return $job->serviceId === 'svc-123' && $job->action === 'upsert';
    });
});

it('dispatchFreshaSync queues PushServiceToFreshaJob', function () {
    Queue::fake();

    invokeDispatchMethod('dispatchFreshaSync', 'svc-456', 'delete');

    Queue::assertPushed(PushServiceToFreshaJob::class, function ($job) {
        return $job->serviceId === 'svc-456' && $job->action === 'delete';
    });
});

// CACHE-12: jitter the dispatch by 0-30s so a bulk service import doesn't burst Square/Fresha
// rate limits. We assert the job is scheduled in the future within the 30s window — the exact
// random value isn't relevant, only that the spreader is wired up.
it('dispatchSquareSync schedules the job with a 0-30s random delay', function () {
    Queue::fake();

    $before = now();
    invokeDispatchMethod('dispatchSquareSync', 'svc-jitter', 'upsert');

    Queue::assertPushed(PushServiceToSquareJob::class, function ($job) use ($before) {
        $delay = $job->delay;
        if (! $delay instanceof \DateTimeInterface) {
            return false;
        }

        $deltaSeconds = $delay->getTimestamp() - $before->getTimestamp();

        return $deltaSeconds >= 0 && $deltaSeconds <= 30;
    });
});

it('dispatchFreshaSync schedules the job with a 0-30s random delay', function () {
    Queue::fake();

    $before = now();
    invokeDispatchMethod('dispatchFreshaSync', 'svc-jitter', 'upsert');

    Queue::assertPushed(PushServiceToFreshaJob::class, function ($job) use ($before) {
        $delay = $job->delay;
        if (! $delay instanceof \DateTimeInterface) {
            return false;
        }

        $deltaSeconds = $delay->getTimestamp() - $before->getTimestamp();

        return $deltaSeconds >= 0 && $deltaSeconds <= 30;
    });
});

// CR-005: bust() failure must not abort the rest of the runHooks pipeline.
// A service whose professional doesn't exist causes bust() to return null.
// The pipeline should complete without exception and dispatch no sync jobs.
it('runHooks completes without exception when professional is not found', function () {
    Queue::fake();

    $service = new Service([
        'id' => 'svc-orphan',
        'professional_id' => '00000000-0000-0000-0000-000000000000',
    ]);

    $observer = app(ServiceObserver::class);
    $reflection = new ReflectionClass($observer);
    $method = $reflection->getMethod('runHooks');
    $method->setAccessible(true);

    // Should not throw — bust() returns null, downstream steps skip gracefully.
    $method->invoke($observer, $service, 'upsert');

    Queue::assertNothingPushed();
});
