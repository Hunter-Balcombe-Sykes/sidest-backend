## Plain English

Six background jobs that run automatically on a timer had no documentation saying when they run, and no test would catch if someone accidentally deleted their scheduler entry. We added a one-line comment to each job class saying exactly when and how often it fires (e.g. "daily at 08:00 UTC"). We also added a test that checks all six jobs are actually wired into the scheduler — if anyone removes a scheduler entry, the test suite will now fail immediately instead of the feature silently stopping in production.

## Technical Summary

**Files changed:**
- `app/Jobs/Notifications/InviteExpirySweepJob.php` — added `// Scheduled: daily at 08:00 UTC via routes/console.php`
- `app/Jobs/Notifications/NudgeStuckOnboardingJob.php` — added `// Scheduled: daily at 09:00 UTC via routes/console.php`
- `app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php` — updated class comment; added `// Scheduled: every Monday at 09:00 UTC via routes/console.php`
- `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php` — updated class comment; added `// Scheduled: every 2 minutes via routes/console.php`
- `app/Jobs/Stripe/VoidExpiredPayoutsJob.php` — added `// Scheduled: daily at 07:00 UTC via routes/console.php`
- `app/Jobs/Stripe/ProcessCommissionPayoutsJob.php` — updated class comment; added `// Scheduled: hourly via routes/console.php`
- `tests/Feature/Console/SchedulerRegistrationTest.php` — new Pest test; resolves `app(Schedule::class)->events()` and asserts each job class appears with the correct cron expression via `$event->description` (set automatically by `Schedule::job()` to `get_class($job)`)

All six jobs were already registered in `routes/console.php` — the fix is purely documentation and enforcement.

## Decisions Made

- **Test checks `$event->description` not a file grep**: `Schedule::job(new SomeJob)` sets `$event->description` to the fully-qualified class name automatically. Checking the live Schedule instance is more robust than a file grep — it catches class renames and namespace moves. Consistent with the existing `HorizonScheduleTest` approach.
- **Test asserts cron expression**: Going beyond presence-only to assert the exact expression prevents schedule drift (e.g. someone accidentally changing `hourly` to `daily` on a time-sensitive job). Small extra cost, high signal value.
- **Kept comment as a single line on the class**: CLAUDE.md asks for purposeful, not decorative comments. One line with the canonical schedule is sufficient; the full registration details live in `routes/console.php`.

## Notes

All six jobs were already registered in `routes/console.php` — no missing entries were found. The audit concern was purely the lack of documentation and enforcement. The `development-v2` branch referenced in the orchestrator prompt does not exist; work was committed to `development`.
