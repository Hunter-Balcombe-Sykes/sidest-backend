<?php

use Illuminate\Support\Facades\Gate;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Policy Coverage Sweep
|--------------------------------------------------------------------------
| Every model under app/Models/ must either (a) have a Gate-registered
| Policy, or (b) appear in POLICY_EXEMPT below with a justification.
|
| Adding a new model? Either register a policy in AppServiceProvider::boot
| or add an entry below explaining why this model doesn't need one.
| Untracked models silently allow IDOR — this test prevents that.
*/

const POLICY_EXEMPT = [
    // Catalog & system tables — no tenant ownership; admin-only or read-only.
    \App\Models\Billing\Plan::class,
    \App\Models\Billing\WebhookEvent::class,
    \App\Models\Core\MediaVariant::class,           // owned via parent SiteMedia
    \App\Models\Core\Staff\SidestStaff::class,      // separate auth surface
    \App\Models\Core\Waitlist\WaitlistSignup::class, // public submission, no actor

    // Shared catalog — one Theme can be applied to many sites; read by public
    // site renderer, mutations are admin-only.
    \App\Models\Core\Site\Theme::class,

    // Public ingestion — write-only via public site endpoints; scoped by
    // ResolvesSiteFromRequest at write time. Reads happen via the analytics
    // API, gated by the parent Site/CommissionPolicy.
    \App\Models\Analytics\CartEvent::class,
    \App\Models\Analytics\LinkClick::class,
    \App\Models\Analytics\SiteVisit::class,

    // Nested under CommissionPayout — gated transitively by CommissionPolicy.
    \App\Models\Retail\CommissionPayoutItem::class,
];

it('every tenant-owned model has a registered policy', function () {
    $modelFiles = (new Finder())
        ->files()
        ->in(app_path('Models'))
        ->name('*.php')
        ->notName('BaseModel.php')
        ->notPath('Views') // read-only DB views are not policy-gated
        ->getIterator();

    $missing = [];

    foreach ($modelFiles as $file) {
        $relative = str_replace([app_path(), '/', '.php'], ['App', '\\', ''], $file->getRealPath());
        if (! class_exists($relative)) {
            continue;
        }

        if (in_array($relative, POLICY_EXEMPT, true)) {
            continue;
        }

        $policy = Gate::getPolicyFor($relative);
        if ($policy === null) {
            $missing[] = $relative;
        }
    }

    expect($missing)->toBe([], "Models without a registered Policy:\n  - " . implode("\n  - ", $missing) . "\n\nEither register one in AppServiceProvider::boot() or add to POLICY_EXEMPT in this test with a justification.");
});

it('every POLICY_EXEMPT entry resolves to a real model class', function () {
    foreach (POLICY_EXEMPT as $class) {
        expect(class_exists($class))->toBeTrue("POLICY_EXEMPT entry {$class} does not resolve to an existing class.");
    }
});
