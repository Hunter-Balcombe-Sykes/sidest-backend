<?php

use App\Jobs\Notifications\InviteExpirySweepJob;
use App\Jobs\Notifications\NudgeStuckOnboardingJob;
use App\Jobs\Notifications\SendWeeklyAnalyticsNotificationJob;
use App\Jobs\Streaming\CheckStreamingLiveStatusJob;
use App\Jobs\Stripe\ProcessCommissionPayoutsJob;
use App\Jobs\Stripe\VoidExpiredPayoutsJob;
use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler-Driven Job Registration
|--------------------------------------------------------------------------
| These jobs have no observer or controller dispatch path — they run only
| via the scheduler. A dropped Schedule::job() entry silently stops the
| feature in production with no test failure. This file prevents that.
*/

it('registers all scheduler-driven jobs', function (string $jobClass, string $expectedExpression) {
    $events = collect(app(Schedule::class)->events());

    $event = $events->first(fn ($e) => ($e->description ?? '') === $jobClass);

    expect($event)->not->toBeNull("{$jobClass} is not registered in the scheduler");
    expect($event->expression)->toBe($expectedExpression, "{$jobClass} has wrong schedule expression");
})->with([
    'InviteExpirySweepJob' => [InviteExpirySweepJob::class,               '0 8 * * *'],
    'NudgeStuckOnboardingJob' => [NudgeStuckOnboardingJob::class,            '0 9 * * *'],
    'SendWeeklyAnalyticsNotificationJob' => [SendWeeklyAnalyticsNotificationJob::class, '0 9 * * 1'],
    'CheckStreamingLiveStatusJob' => [CheckStreamingLiveStatusJob::class,        '*/2 * * * *'],
    'VoidExpiredPayoutsJob' => [VoidExpiredPayoutsJob::class,              '0 7 * * *'],
    'ProcessCommissionPayoutsJob' => [ProcessCommissionPayoutsJob::class,        '0 * * * *'],
]);
