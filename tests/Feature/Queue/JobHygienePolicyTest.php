<?php

use Illuminate\Contracts\Queue\ShouldQueue;

it('every ShouldQueue job defines $tries, backoff, and $timeout', function () {
    $jobsPath = app_path('Jobs');
    $concernsPath = app_path('Jobs/Concerns');

    // Jobs explicitly exempt from the hygiene check.
    // Format: 'App\\Jobs\\SomeSpecialJob' => 'reason why it is exempt'
    $exemptions = [
        // (none currently — all jobs have been fixed)
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($jobsPath)
    );

    $violations = [];

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Skip Concerns/ — those are traits, not concrete jobs.
        if (str_starts_with($file->getRealPath(), $concernsPath)) {
            continue;
        }

        // Derive the fully-qualified class name from the file path.
        $relativePath = str_replace([$jobsPath.DIRECTORY_SEPARATOR, '.php'], '', $file->getRealPath());
        $className = 'App\\Jobs\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

        if (! class_exists($className)) {
            continue;
        }

        $reflection = new ReflectionClass($className);

        // Skip abstract classes and anything that isn't a queued job.
        if ($reflection->isAbstract() || ! $reflection->implementsInterface(ShouldQueue::class)) {
            continue;
        }

        if (isset($exemptions[$className])) {
            continue;
        }

        $missing = [];

        if (! property_exists($className, 'tries')) {
            $missing[] = '$tries';
        }

        // Accept either a $backoff property or a backoff() method (e.g. traits like HasCloudflareRetryPolicy).
        $hasBackoff = property_exists($className, 'backoff') || $reflection->hasMethod('backoff');
        if (! $hasBackoff) {
            $missing[] = '$backoff or backoff()';
        }

        if (! property_exists($className, 'timeout')) {
            $missing[] = '$timeout';
        }

        if ($missing !== []) {
            $violations[$className] = $missing;
        }
    }

    $message = '';
    if ($violations !== []) {
        $message = "Jobs missing hygiene properties:\n";
        foreach ($violations as $class => $props) {
            $message .= "  - {$class}: missing ".implode(', ', $props)."\n";
        }
    }

    expect($violations)->toBeEmpty($message);
});
