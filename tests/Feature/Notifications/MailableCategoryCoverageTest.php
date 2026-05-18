<?php

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Mailable Category Coverage Sweep
|--------------------------------------------------------------------------
| Every category registered in config('partna.notifications.mailables') must
| have at least one `publish(category: 'X', ...)` call site in app/. Dead
| wiring (Mailable + view + config entry but no emitter) is a footgun —
| someone will assume "we already send X emails" when nothing ever fires.
|
| Adding a new category? Wire an emit site OR add the category to
| MAILABLE_COVERAGE_EXEMPT with a justification.
|
| Mirror of tests/Feature/Security/PolicyCoverageTest.php.
*/

const MAILABLE_COVERAGE_EXEMPT = [
    // No exemptions today. If a category is genuinely emit-only via a path
    // other than publish() (e.g. a future direct-send mailable that's still
    // categorised for preference toggling), add it here with the reason.
];

it('every configured mailable category has at least one publish() call site', function () {
    $categories = array_keys((array) config('partna.notifications.mailables', []));
    expect($categories)->not->toBeEmpty('no mailable categories registered — config regression');

    $appPath = base_path('app');
    $finder = (new Finder)->files()->in($appPath)->name('*.php');

    // Build the haystack once.
    $haystack = '';
    foreach ($finder as $file) {
        $haystack .= $file->getContents()."\n";
    }

    $missing = [];
    foreach ($categories as $category) {
        if (in_array($category, MAILABLE_COVERAGE_EXEMPT, true)) {
            continue;
        }

        // Match both named-arg style `category: 'X'` and array style `'category' => 'X'`.
        $named = "category: '{$category}'";
        $array = "'category' => '{$category}'";

        if (! str_contains($haystack, $named) && ! str_contains($haystack, $array)) {
            $missing[] = $category;
        }
    }

    expect($missing)->toBe(
        [],
        'These mailable categories are registered in config but have NO publish() emit sites in app/: '
        .implode(', ', $missing)
        .'. Either wire an emit site, remove the category from config/partna.php, or add it to MAILABLE_COVERAGE_EXEMPT.',
    );
});
