<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationController;
use App\Jobs\Notifications\SendStaffBroadcastEmailsJob;
use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Models\Core\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Covers the staff broadcast store endpoint:
 *  - category whitelist + persistence
 *  - category-driven retention
 *  - targeted+categorised → unified publisher job
 *  - global → newsletter job
 */
beforeEach(function () {
    Config::set('database.connections.pgsql', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $conn = DB::connection('pgsql');

    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS notifications");
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        type TEXT NOT NULL,
        category TEXT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        cta_url TEXT NULL,
        primary_action_label TEXT NULL,
        secondary_action_label TEXT NULL,
        secondary_action_url TEXT NULL,
        severity TEXT NOT NULL DEFAULT "info",
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        email_sent_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    Config::set('partna.notification_retention_days', [
        'default' => 30,
        'policy_update' => 365,
        'incident' => 14,
        'feature_announcement' => 30,
    ]);

    Carbon::setTestNow('2026-04-14 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('persists the category on the notification row', function () {
    $controller = app(StaffNotificationController::class);
    $request = Request::create('/staff/notifications', 'POST', [
        'type' => 'info',
        'title' => 'Policy update',
        'body' => 'New ToS.',
        'category' => 'policy_update',
    ]);

    $response = $controller->store($request);
    expect($response->getStatusCode())->toBe(201);

    $payload = json_decode($response->getContent(), true);
    $notification = Notification::query()->findOrFail($payload['notification']['id']);

    expect($notification->category)->toBe('policy_update');
});

it('applies category-keyed retention when ends_at is omitted', function (string $category, int $days) {
    $controller = app(StaffNotificationController::class);
    $request = Request::create('/staff/notifications', 'POST', [
        'type' => 'info',
        'title' => 'Msg',
        'body' => 'Body',
        'category' => $category,
    ]);

    $response = $controller->store($request);
    expect($response->getStatusCode())->toBe(201);

    $payload = json_decode($response->getContent(), true);
    $notification = Notification::query()->findOrFail($payload['notification']['id']);

    $expected = Carbon::parse('2026-04-14 12:00:00')->addDays($days);
    expect($notification->ends_at->diffInSeconds($expected, true))->toBeLessThan(60);
})->with([
    ['policy_update', 365],
    ['incident', 14],
    ['feature_announcement', 30],
]);

it('falls back to default retention when category is null', function () {
    $controller = app(StaffNotificationController::class);
    $request = Request::create('/staff/notifications', 'POST', [
        'type' => 'info',
        'title' => 'No-category broadcast',
        'body' => 'Body',
    ]);

    $response = $controller->store($request);
    expect($response->getStatusCode())->toBe(201);

    $payload = json_decode($response->getContent(), true);
    $notification = Notification::query()->findOrFail($payload['notification']['id']);

    $expected = Carbon::parse('2026-04-14 12:00:00')->addDays(30);
    expect($notification->ends_at->diffInSeconds($expected, true))->toBeLessThan(60);
});

it('dispatches the unified publisher job for targeted + categorised + send_email=true', function () {
    Bus::fake();

    $controller = app(StaffNotificationController::class);
    $proId = '11111111-1111-1111-1111-111111111111';
    $request = Request::create('/staff/notifications', 'POST', [
        'professional_id' => $proId,
        'type' => 'critical',
        'title' => 'Incident',
        'body' => 'Outage detected.',
        'category' => 'incident',
        'send_email' => true,
    ]);

    $response = $controller->store($request);
    expect($response->getStatusCode())->toBe(201);

    Bus::assertDispatched(SendTransactionalNotificationEmailJob::class, function ($job) use ($proId) {
        return $job->category === 'incident' && $job->professionalId === $proId;
    });
    Bus::assertNotDispatched(SendStaffBroadcastEmailsJob::class);
});

it('dispatches the newsletter job for global broadcasts with send_email=true', function () {
    Bus::fake();

    $controller = app(StaffNotificationController::class);
    $request = Request::create('/staff/notifications', 'POST', [
        'type' => 'info',
        'title' => 'Global',
        'body' => 'Heads up.',
        'send_email' => true,
    ]);

    $response = $controller->store($request);
    expect($response->getStatusCode())->toBe(201);

    Bus::assertDispatched(SendStaffBroadcastEmailsJob::class);
    Bus::assertNotDispatched(SendTransactionalNotificationEmailJob::class);
});

it('rejects an invalid category with 422', function () {
    $controller = app(StaffNotificationController::class);
    $request = Request::create('/staff/notifications', 'POST', [
        'type' => 'info',
        'title' => 'Hi',
        'body' => 'There',
        'category' => 'garbage',
    ]);

    try {
        $controller->store($request);
        expect(false)->toBeTrue('expected validation exception');
    } catch (\Illuminate\Validation\ValidationException $e) {
        expect($e->status)->toBe(422);
        expect($e->errors())->toHaveKey('category');
    }
});
