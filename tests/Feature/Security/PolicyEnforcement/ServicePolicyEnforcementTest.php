<?php

use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalServiceCategoryController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalServiceController;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupServicesTable();
    setupServiceCategoriesTable();
});

// --- Service: owner access ---

it('allows the owner to view their own service', function () {
    $owner = createTenant('svc-view-owner');
    $service = createServiceFor($owner);
    $req = tenantRequestAs($owner);

    $response = app(ProfessionalServiceController::class)->show($req, $service);

    expect($response->getStatusCode())->toBe(200);
});

// --- Service: non-owner blocked ---

it('blocks a non-owner from viewing another tenants service with 404', function () {
    $owner = createTenant('svc-view-owner-2');
    $intruder = createTenant('svc-view-intruder');
    $service = createServiceFor($owner);
    $req = tenantRequestAs($intruder);

    try {
        app(ProfessionalServiceController::class)->show($req, $service);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

it('blocks a non-owner from updating another tenants service with 404', function () {
    $owner = createTenant('svc-update-owner');
    $intruder = createTenant('svc-update-intruder');
    $service = createServiceFor($owner);

    $req = tenantRequestAs($intruder, ['title' => 'Hacked'], 'PATCH');

    try {
        app(ProfessionalServiceController::class)->update(
            \App\Http\Requests\Api\Professional\Services\UpdateServiceRequest::createFrom($req),
            $service
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

it('blocks a non-owner from deleting another tenants service with 404', function () {
    $owner = createTenant('svc-destroy-owner');
    $intruder = createTenant('svc-destroy-intruder');
    $service = createServiceFor($owner);
    $req = tenantRequestAs($intruder, [], 'DELETE');

    try {
        app(ProfessionalServiceController::class)->destroy($req, $service);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

// --- Service: pending deletion blocked on writes ---

it('blocks a pending-deletion owner from updating a service with 423', function () {
    $owner = createTenant('svc-pending-update');
    DB::connection('pgsql')->table('core.professionals')->where('id', $owner->id)->update([
        'status' => 'pending_deletion',
    ]);
    $owner->refresh();

    $service = createServiceFor($owner);
    $req = tenantRequestAs($owner, ['title' => 'New Title'], 'PATCH');

    try {
        app(ProfessionalServiceController::class)->update(
            \App\Http\Requests\Api\Professional\Services\UpdateServiceRequest::createFrom($req),
            $service
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
        expect($e->getMessage())->toBe('Account is pending deletion.');
    }
});

// --- ServiceCategory: owner access ---

it('allows the owner to view their own service category', function () {
    $owner = createTenant('cat-view-owner');
    $category = createServiceCategoryFor($owner);
    $req = tenantRequestAs($owner);

    $response = app(ProfessionalServiceCategoryController::class)->show($req, $category);

    expect($response->getStatusCode())->toBe(200);
});

// --- ServiceCategory: non-owner blocked ---

it('blocks a non-owner from viewing another tenants service category with 404', function () {
    $owner = createTenant('cat-view-owner-2');
    $intruder = createTenant('cat-view-intruder');
    $category = createServiceCategoryFor($owner);
    $req = tenantRequestAs($intruder);

    try {
        app(ProfessionalServiceCategoryController::class)->show($req, $category);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

it('blocks a non-owner from updating another tenants service category with 404', function () {
    $owner = createTenant('cat-update-owner');
    $intruder = createTenant('cat-update-intruder');
    $category = createServiceCategoryFor($owner);

    $req = tenantRequestAs($intruder, ['title' => 'Hacked'], 'PATCH');

    try {
        app(ProfessionalServiceCategoryController::class)->update(
            \App\Http\Requests\Api\Professional\Services\UpdateServiceCategoryRequest::createFrom($req),
            $category
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

it('blocks a non-owner from deleting another tenants service category with 404', function () {
    $owner = createTenant('cat-destroy-owner');
    $intruder = createTenant('cat-destroy-intruder');
    $category = createServiceCategoryFor($owner);
    $req = tenantRequestAs($intruder, [], 'DELETE');

    try {
        app(ProfessionalServiceCategoryController::class)->destroy($req, $category);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

it('blocks a pending-deletion owner from creating a service with 423', function () {
    $owner = createTenant('pro-pending-create-svc');
    DB::connection('pgsql')->table('core.professionals')->where('id', $owner->id)->update([
        'status' => 'pending_deletion',
    ]);
    $owner->refresh();

    $req = tenantRequestAs($owner, [
        'name' => 'Test Service',
        'duration_minutes' => 30,
        'price' => 50,
    ], 'POST');

    try {
        app(\App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalServiceController::class)->store(
            \App\Http\Requests\Api\Professional\Services\StoreServiceRequest::createFrom($req)
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
        expect($e->status())->toBe(423);
        expect($e->getMessage())->toBe('Account is pending deletion.');
    }
});

it('blocks a pending-deletion owner from updating a service category with 423', function () {
    $owner = createTenant('cat-pending-update');
    DB::connection('pgsql')->table('core.professionals')->where('id', $owner->id)->update([
        'status' => 'pending_deletion',
    ]);
    $owner->refresh();

    $category = createServiceCategoryFor($owner);
    $req = tenantRequestAs($owner, ['title' => 'New Title'], 'PATCH');

    try {
        app(ProfessionalServiceCategoryController::class)->update(
            \App\Http\Requests\Api\Professional\Services\UpdateServiceCategoryRequest::createFrom($req),
            $category
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
        expect($e->getMessage())->toBe('Account is pending deletion.');
    }
});

// --- Fresha integration: service ownership via ServicePolicy ---

it('blocks a non-owner from pushing another tenants service to Fresha with 404', function () {
    $owner = createTenant('svc-fresha-push-owner');
    $intruder = createTenant('svc-fresha-push-intruder');
    $service = createServiceFor($owner);
    $req = tenantRequestAs($intruder, [], 'POST');

    try {
        app(\App\Http\Controllers\Api\Professional\FreshaIntegration\FreshaIntegrationController::class)
            ->pushServiceNow($req, $service, \Mockery::mock(\App\Services\Fresha\FreshaServiceSyncService::class));
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

// --- Square integration: service ownership via ServicePolicy ---

it('blocks a non-owner from pushing another tenants service to Square with 404', function () {
    $owner = createTenant('svc-square-push-owner');
    $intruder = createTenant('svc-square-push-intruder');
    $service = createServiceFor($owner);
    $req = tenantRequestAs($intruder, [], 'POST');

    try {
        app(\App\Http\Controllers\Api\Professional\SquareIntegration\SquareIntegrationController::class)
            ->pushServiceNow($req, $service, \Mockery::mock(\App\Services\Square\SquareServiceSyncService::class));
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});
