<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffLinkBlockManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffSectionManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffProfessionalController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffSiteManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffServiceManagementController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffAnalyticsController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffMeController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffSiteController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCustomerManagementController;
use Illuminate\Support\Facades\Route;

// Authorised Staff Viewing
Route::prefix('staff')
    ->middleware(['supabase.jwt', 'staff'])
    ->whereUuid('professional')
    ->scopeBindings()
    ->group(function () {

    // Staff Dashboard
    Route::get('/me', [StaffMeController::class, 'show']);

    // Staff can see Site
    Route::get('/sites/{subdomain}', [StaffSiteController::class, 'show'])
        ->where('subdomain', '[A-Za-z0-9-]+');

    // Search barbers
    Route::get('/professionals', [StaffProfessionalController::class, 'index']);

    // View one barber
    Route::get('/professionals/{professional}', [StaffProfessionalController::class, 'show']);
    // Soft delete (regular staff)
    Route::delete('/professionals/{professional}', [StaffProfessionalController::class, 'destroy']);
    // Restore
    Route::post('/professionals/{professional}/restore', [StaffProfessionalController::class, 'restore']);

    // View Customers
    Route::get('/professionals/{professional}/customers', [StaffCustomerManagementController::class, 'index']);
    Route::get('/professionals/{professional}/customers/{customer}', [StaffCustomerManagementController::class, 'show'])
        ->whereUuid('customer');
    Route::post('/professionals/{professional}/customers/{customer}/restore', [StaffCustomerManagementController::class, 'restore'])
        ->whereUuid('customer');

    // View Services
    Route::get('/professionals/{professional}/services', [StaffServiceManagementController::class, 'index']);
    Route::get('/professionals/{professional}/services/{service}', [StaffServiceManagementController::class, 'show'])
        ->whereUuid('service');
    Route::post('/professionals/{professional}/services/{service}/restore', [StaffServiceManagementController::class, 'restore'])
        ->whereUuid('service');

    // View that barber's site data
    Route::get('/professionals/{professional}/site', [StaffSiteController::class, 'showByProfessional']);

    // View analytics summary
    Route::get('/professionals/{professional}/analytics', [StaffAnalyticsController::class, 'summary']);

    // View Link Blocks
    Route::get('/professionals/{professional}/links', [StaffLinkBlockManagementController::class, 'index']);

    // View Sections
    Route::get('/professionals/{professional}/sections', [StaffSectionManagementController::class, 'index']);
});

// Authorised Staff Admin Editing
Route::prefix('staff')
    ->middleware(['supabase.jwt', 'staff', 'staff.admin'])
    ->whereUuid('professional')
    ->scopeBindings()
    ->group(function () {

    // Suspend / unsuspend barber
    Route::patch('/professionals/{professional}/status', [StaffProfessionalController::class, 'updateStatus']);
    Route::patch('/professionals/{professional}', [StaffProfessionalController::class, 'update']);
    // Restore
    Route::post('/professionals/{professional}/restore', [StaffProfessionalController::class, 'restore']);
    // Hard delete (admin only)
    Route::delete('/professionals/{professional}/force', [StaffProfessionalController:: class, 'forceDestroy']);

    // Admin edit/delete customers for a professional
    Route::patch('/professionals/{professional}/customers/{customer}', [StaffCustomerManagementController::class, 'update'])
        ->whereUuid('customer');
    Route::delete('/professionals/{professional}/customers/{customer}', [StaffCustomerManagementController::class, 'destroy'])
        ->whereUuid('customer');
    Route::delete('/professionals/{professional}/customers/{customer}/hard', [StaffCustomerManagementController::class, 'forceDestroy'])
        ->whereUuid('customer');

    // Edit Services
    Route::post('/professionals/{professional}/services', [StaffServiceManagementController::class, 'store']);
    Route::patch('/professionals/{professional}/services/{service}', [StaffServiceManagementController::class, 'update'])
        ->whereUuid('service');
    Route::delete('/professionals/{professional}/services/{service}', [StaffServiceManagementController::class, 'destroy'])
        ->whereUuid('service');
    Route::delete('/professionals/{professional}/services/{service}/hard', [StaffServiceManagementController::class, 'forceDestroy'])
        ->whereUuid('service');
    Route::post('/professionals/{professional}/services/reorder', [StaffServiceManagementController::class, 'reorder']);

    // Edit site
    Route::patch('/professionals/{professional}/site', [StaffSiteManagementController::class, 'update']);

    // Edit Link Blocks
    Route::post('/professionals/{professional}/links', [StaffLinkBlockManagementController::class, 'store']);
    Route::patch('/professionals/{professional}/links/{block}', [StaffLinkBlockManagementController::class, 'update'])
        ->whereUuid('block');
    Route::delete('/professionals/{professional}/links/{block}', [StaffLinkBlockManagementController::class, 'destroy'])
        ->whereUuid('block');
    Route::post('/professionals/{professional}/links/reorder', [StaffLinkBlockManagementController::class, 'reorder']);

    // Edit Sections
    Route::put('/professionals/{professional}/sections/{blockType}', [StaffSectionManagementController::class, 'upsert'])
        ->where('blockType', '[a-z0-9_-]+');
    Route::post('/professionals/{professional}/sections/reorder', [StaffSectionManagementController::class, 'reorder']);
    Route::delete('/professionals/{professional}/sections/{blockType}', [StaffSectionManagementController::class, 'remove'])
        ->where('blockType', '[a-z0-9_-]+');

    // Notifications
    Route::post('/notifications', [StaffNotificationController::class, 'store']);
});
