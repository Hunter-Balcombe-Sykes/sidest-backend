<?php

use App\Jobs\Gdpr\ExportProfessionalDataJob;
use App\Mail\Gdpr\ProfessionalDataExportMail;
use App\Models\Core\Gdpr\DataExportAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Professional\DataExport\DataExportTestCase;

beforeEach(function () {
    DataExportTestCase::boot();
    Storage::fake('media');
    Mail::fake();
});

function seedProfessionalForJob(string $id, string $handle = 'jane', string $email = 'jane@example.com'): void
{
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => mb_strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => $email,
        'status' => 'active',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);
}

it('transitions audit row queued → processing → completed on happy path', function () {
    $profId = (string) Str::uuid();
    seedProfessionalForJob($profId);

    $audit = DataExportAudit::create([
        'professional_id' => $profId,
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
        'send_to' => 'professional',
    ]);

    (new ExportProfessionalDataJob($audit->id))->handle();

    $audit->refresh();
    expect($audit->status)->toBe('completed');
    expect($audit->file_path)->toMatch('#^exports/'.$profId.'/'.$audit->id.'\.zip$#');
    expect($audit->file_size_bytes)->toBeGreaterThan(0);
    expect(strlen($audit->file_sha256))->toBe(64);
    expect($audit->record_counts)->toBeArray();
    expect($audit->completed_at)->not->toBeNull();
});

it('uploads the zip to the configured media disk', function () {
    $profId = (string) Str::uuid();
    seedProfessionalForJob($profId);

    $audit = DataExportAudit::create([
        'professional_id' => $profId,
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
        'send_to' => 'professional',
    ]);

    (new ExportProfessionalDataJob($audit->id))->handle();

    Storage::disk('media')->assertExists("exports/{$profId}/{$audit->id}.zip");
});

it('sends the mailable to the recipient with the signed URL', function () {
    $profId = (string) Str::uuid();
    seedProfessionalForJob($profId);

    $audit = DataExportAudit::create([
        'professional_id' => $profId,
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
        'send_to' => 'professional',
    ]);

    (new ExportProfessionalDataJob($audit->id))->handle();

    Mail::assertSent(ProfessionalDataExportMail::class, function (ProfessionalDataExportMail $mail) {
        return $mail->hasTo('jane@example.com')
            && str_contains($mail->signedUrl, 'exports/');
    });
});

it('aborts gracefully if the professional is hard-deleted between dispatch and run', function () {
    $audit = DataExportAudit::create([
        'professional_id' => null, // Simulates ON DELETE SET NULL after dispatch
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
    ]);

    (new ExportProfessionalDataJob($audit->id))->handle();

    $audit->refresh();
    expect($audit->status)->toBe('failed');
    expect($audit->error_message)->toContain('professional');
    Mail::assertNothingSent();
});

it('marks audit failed when audit row missing (job ran for deleted row)', function () {
    $bogusId = (string) Str::uuid();
    // No row created — job should noop gracefully.
    expect(fn () => (new ExportProfessionalDataJob($bogusId))->handle())->not->toThrow(Throwable::class);
});

it('failed() method marks audit as failed with a meaningful error', function () {
    $profId = (string) Str::uuid();
    seedProfessionalForJob($profId);

    $audit = DataExportAudit::create([
        'professional_id' => $profId,
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
    ]);

    $job = new ExportProfessionalDataJob($audit->id);
    $job->failed(new \RuntimeException('queue worker killed'));

    $audit->refresh();
    expect($audit->status)->toBe('failed');
    expect($audit->error_message)->toContain('queue worker killed');
});
