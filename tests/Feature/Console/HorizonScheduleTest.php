<?php

use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| Horizon Schedule Wiring
|--------------------------------------------------------------------------
| Without `horizon:snapshot` running on a schedule, the Metrics tab in
| Horizon stays empty (no jobs/queue throughput data is captured into
| Redis). This test prevents the schedule from being silently removed.
*/

it('schedules horizon:snapshot every five minutes', function () {
    $events = collect(app(Schedule::class)->events());

    $snapshot = $events->first(fn ($event) => str_contains((string) $event->command, 'horizon:snapshot'));

    expect($snapshot)->not->toBeNull('horizon:snapshot is not scheduled');
    expect($snapshot->expression)->toBe('*/5 * * * *');
});
