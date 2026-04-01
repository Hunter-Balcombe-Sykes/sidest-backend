<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffLinkBlockManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffSectionManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffProfessionalController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffSiteManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffServiceManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffServiceCategoryManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffSubscriptionManagementController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffAnalyticsController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffMeController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationEmailPolicyController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffSiteController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCustomerManagementController;
use Illuminate\Support\Facades\Route;

// Authorised Staff Viewing
Route::prefix('staff')
    ->middleware(['supabase.jwt', 'staff', 'throttle:staff'])
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
    Route::post('/professionals/{professional}/restore', [StaffProfessionalController::class, 'restore'])
        ->withTrashed();

    // View Customers
    Route::get('/professionals/{professional}/customers', [StaffCustomerManagementController::class, 'index']);
    Route::get('/professionals/{professional}/customers/{customer}', [StaffCustomerManagementController::class, 'show'])
        ->whereUuid('customer');
    Route::post('/professionals/{professional}/customers/{customer}/restore', [StaffCustomerManagementController::class, 'restore'])
        ->whereUuid('customer')
        ->withTrashed();

    // View Services
    Route::get('/professionals/{professional}/services', [StaffServiceManagementController::class, 'index']);
    Route::get('/professionals/{professional}/services/{service}', [StaffServiceManagementController::class, 'show'])
        ->whereUuid('service')
        ->withTrashed();
    Route::post('/professionals/{professional}/services/{service}/restore', [StaffServiceManagementController::class, 'restore'])
        ->whereUuid('service')
        ->withTrashed();

    // View Service Categories
    Route::get('/professionals/{professional}/service-categories', [StaffServiceCategoryManagementController::class, 'index']);
    Route::get('/professionals/{professional}/service-categories/{category}', [StaffServiceCategoryManagementController::class, 'show'])
        ->whereUuid('category')
        ->withTrashed();
    Route::post('/professionals/{professional}/service-categories/{category}/restore', [StaffServiceCategoryManagementController::class, 'restore'])
        ->whereUuid('category')
        ->withTrashed();

    // View that barber's site data
    Route::get('/professionals/{professional}/site', [StaffSiteController::class, 'showByProfessional']);

    // View analytics summary
    Route::get('/professionals/{professional}/analytics', [StaffAnalyticsController::class, 'summary']);

    // View Link Blocks
    Route::get('/professionals/{professional}/links', [StaffLinkBlockManagementController::class, 'index']);

    // View Sections
    Route::get('/professionals/{professional}/sections', [StaffSectionManagementController::class, 'index']);

    // View Subscription
    Route::get('/professionals/{professional}/subscription', [StaffSubscriptionManagementController::class, 'show']);
});

// Authorised Staff Admin Editing
Route::prefix('staff')
    ->middleware(['supabase.jwt', 'staff', 'staff.admin', 'throttle:staff'])
    ->whereUuid('professional')
    ->scopeBindings()
    ->group(function () {

    // Suspend / unsuspend barber
    Route::patch('/professionals/{professional}/status', [StaffProfessionalController::class, 'updateStatus']);
    Route::patch('/professionals/{professional}', [StaffProfessionalController::class, 'update']);
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

    // Edit Service Categories
    Route::post('/professionals/{professional}/service-categories', [StaffServiceCategoryManagementController::class, 'store']);
    Route::patch('/professionals/{professional}/service-categories/{category}', [StaffServiceCategoryManagementController::class, 'update'])
        ->whereUuid('category');
    Route::delete('/professionals/{professional}/service-categories/{category}', [StaffServiceCategoryManagementController::class, 'destroy'])
        ->whereUuid('category');
    Route::delete('/professionals/{professional}/service-categories/{category}/hard', [StaffServiceCategoryManagementController::class, 'forceDestroy'])
        ->whereUuid('category');

    // Reorder categories
    Route::post('/professionals/{professional}/service-categories/reorder', [StaffServiceCategoryManagementController::class, 'reorder']);

    // Full UI layout reorder (categories + services) for staff admin
    Route::post('/professionals/{professional}/services/reorder-layout', [StaffServiceManagementController::class, 'reorderLayout']);

    // Edit site
    Route::patch('/professionals/{professional}/site', [StaffSiteManagementController::class, 'update']);

    // Edit Link Blocks
    Route::post('/professionals/{professional}/links', [StaffLinkBlockManagementController::class, 'store']);
    Route::patch('/professionals/{professional}/links/{linkBlock}', [StaffLinkBlockManagementController::class, 'update'])
        ->whereUuid('linkBlock');
    Route::delete('/professionals/{professional}/links/{linkBlock}', [StaffLinkBlockManagementController::class, 'destroy'])
        ->whereUuid('linkBlock');
    Route::post('/professionals/{professional}/links/reorder', [StaffLinkBlockManagementController::class, 'reorder']);

    // Edit Sections
    Route::put('/professionals/{professional}/sections/{blockType}', [StaffSectionManagementController::class, 'upsert'])
        ->where('blockType', '[a-z0-9_-]+');
    Route::post('/professionals/{professional}/sections/reorder', [StaffSectionManagementController::class, 'reorder']);
    Route::delete('/professionals/{professional}/sections/{blockType}', [StaffSectionManagementController::class, 'remove'])
        ->where('blockType', '[a-z0-9_-]+');

    // Manage Subscription
    Route::patch('/professionals/{professional}/subscription', [StaffSubscriptionManagementController::class, 'update']);
    Route::post('/professionals/{professional}/subscription/cancel', [StaffSubscriptionManagementController::class, 'cancel']);
    Route::post('/professionals/{professional}/subscription/resume', [StaffSubscriptionManagementController::class, 'resume']);

    // Notifications
    Route::post('/notifications', [StaffNotificationController::class, 'store']);

    // Notification email policies
    Route::get('/notification-email-policies', [StaffNotificationEmailPolicyController::class, 'indexGlobal']);
    Route::patch('/notification-email-policies', [StaffNotificationEmailPolicyController::class, 'updateGlobal']);
    Route::get('/professionals/{professional}/notification-email-policies', [StaffNotificationEmailPolicyController::class, 'indexProfessional']);
    Route::patch('/professionals/{professional}/notification-email-policies', [StaffNotificationEmailPolicyController::class, 'updateProfessional']);
});
