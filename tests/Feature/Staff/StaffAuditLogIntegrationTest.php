<?php

use App\Models\Core\Staff\StaffAuditEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupPartnaStaffTable();
    setupProfessionalsTable();
    setupSitesTable();

    // Add admin_notes column that StaffProfessionalController::update() writes.
    // SQLite doesn't support IF NOT EXISTS in ALTER TABLE, so wrap in try/catch.
    try {
        DB::connection('pgsql')->statement('ALTER TABLE core.professionals ADD COLUMN admin_notes TEXT NULL');
    } catch (\Throwable) {
        // Column already exists — no-op.
    }

    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        professional_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL,
        http_method TEXT NOT NULL,
        status_code INTEGER NOT NULL,
        payload_summary TEXT NOT NULL DEFAULT "{}",
        ip TEXT,
        user_agent TEXT,
        created_at TEXT
    )');
});

/**
 * Stub VerifySupabaseJwt so the test HTTP client doesn't need a real JWT.
 * Sets supabase_uid so EnsurePartnaStaff can do its real DB lookup.
 */
function actingAsStaffWithUid(string $supabaseUid): \Pest\Support\HigherOrderTapProxy
{
    app()->bind(\App\Http\Middleware\Auth\VerifySupabaseJwt::class, function () use ($supabaseUid) {
        return new class($supabaseUid)
        {
            public function __construct(private readonly string $uid) {}

            public function handle(\Illuminate\Http\Request $request, \Closure $next)
            {
                $request->attributes->set('supabase_uid', $this->uid);
                $request->attributes->set('supabase_claims', [
                    'sub' => $this->uid,
                    'email' => 'staff-test@partna.au',
                    'email_verified' => true,
                ]);

                return $next($request);
            }
        };
    });

    return test();
}

it('inserts an audit row when staff PATCHes a professional', function () {
    $authUid = (string) Str::uuid();
    $staffId = (string) Str::uuid();
    $professionalId = (string) Str::uuid();

    DB::table('core.partna_staff')->insert([
        'id' => $staffId,
        'auth_user_id' => $authUid,
        'role' => 'admin',
        'primary_email' => 'admin@partna.au',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::table('core.professionals')->insert([
        'id' => $professionalId,
        'handle' => 'acme',
        'display_name' => 'Acme Brand',
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $response = actingAsStaffWithUid($authUid)
        ->patchJson("/api/staff/professionals/{$professionalId}", [
            'admin_notes' => 'VIP — do not suspend',
        ]);

    $response->assertSuccessful();

    $row = StaffAuditEntry::query()->first();
    expect($row)->not->toBeNull()
        ->and($row->staff_id)->toBe($staffId)
        ->and($row->staff_email_snapshot)->toBe('admin@partna.au')
        ->and($row->professional_id)->toBe($professionalId)
        ->and($row->professional_handle_snapshot)->toBe('acme')
        ->and($row->http_method)->toBe('PATCH')
        ->and($row->status_code)->toBe(200);
});

it('does NOT insert an audit row when staff GETs a professional', function () {
    $authUid = (string) Str::uuid();
    $professionalId = (string) Str::uuid();

    DB::table('core.partna_staff')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => $authUid,
        'role' => 'support',
        'primary_email' => 'support@partna.au',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::table('core.professionals')->insert([
        'id' => $professionalId,
        'handle' => 'acme',
        'display_name' => 'Acme Brand',
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Ignore the response status — the controller may 500 due to missing
    // test tables; what we're asserting is that the middleware skips GET.
    actingAsStaffWithUid($authUid)
        ->getJson("/api/staff/professionals/{$professionalId}");

    expect(StaffAuditEntry::query()->count())->toBe(0);
});
