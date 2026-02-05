<?php

use App\Http\Controllers\Api\Professional\Notifications\NotificationController;
use App\Http\Controllers\Api\Professional\ProfessionalAnalyticsController;
use App\Http\Controllers\Api\Professional\ProfessionalController;
use App\Http\Controllers\Api\Professional\ProfessionalCustomerController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalLinkBlockController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalSectionBlockController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalServiceController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalServiceCategoryController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalSiteController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalThemeController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalGalleryController;
use App\Http\Controllers\Api\Professional\Uploads\ProfessionalUploadController;
use App\Http\Controllers\Api\Professional\Notifications\ProfessionalEmailSubscriptionController;
use App\Http\Controllers\Api\PublicSite\SiteVisibilityController;
use Illuminate\Support\Facades\Route;

// Authorised Professional Logged In
Route::middleware(['supabase.jwt', 'current.pro'])
    ->group(function () {

    // Show & Edit Details
    Route::get('/me', [ProfessionalController::class, 'show']);
    Route::patch('/me', [ProfessionalController::class, 'update']);

    // View Site Details (optional)
    Route::get('/site', [ProfessionalSiteController::class, 'show']);

    // Update Site Details
    Route::patch('/site', [ProfessionalSiteController::class, 'update']);
    Route::patch('/site/visibility', [SiteVisibilityController::class, 'update']);

    // Service Details and Edit
    Route::get('/services', [ProfessionalServiceController::class, 'index']);
    Route::post('/services', [ProfessionalServiceController::class, 'store']);
    Route::get('/services/{service}', [ProfessionalServiceController::class, 'show'])
        ->whereUuid('service');
    Route::patch('/services/{service}', [ProfessionalServiceController::class, 'update'])
        ->whereUuid('service');
    Route::delete('/services/{service}', [ProfessionalServiceController::class, 'destroy'])
        ->whereUuid('service');
    Route::post('/services/reorder', [ProfessionalServiceController::class, 'reorder']);
    Route::post('/services/{service}/restore', [ProfessionalServiceController::class, 'restore'])
        ->whereUuid('service')
        ->withTrashed();

    // NEW: Service Categories (CRUD + reorder)
    Route::get('/service-categories', [ProfessionalServiceCategoryController::class, 'index']);
    Route::post('/service-categories', [ProfessionalServiceCategoryController::class, 'store']);
    Route::get('/service-categories/{category}', [ProfessionalServiceCategoryController::class, 'show'])
        ->whereUuid('category')
        ->withTrashed();
    Route::patch('/service-categories/{category}', [ProfessionalServiceCategoryController::class, 'update'])
        ->whereUuid('category');
    Route::delete('/service-categories/{category}', [ProfessionalServiceCategoryController::class, 'destroy'])
        ->whereUuid('category');
    Route::post('/service-categories/reorder', [ProfessionalServiceCategoryController::class, 'reorder']);
    Route::post('/service-categories/{category}/restore', [ProfessionalServiceCategoryController::class, 'restore'])
        ->whereUuid('category')
        ->withTrashed();
    Route::post('/services/reorder-layout', [ProfessionalServiceController::class, 'reorderLayout']);


    // View Analytics
    Route::get('/analytics', [ProfessionalAnalyticsController::class, 'summary']);

    // Links
    Route::get('/links', [ProfessionalLinkBlockController::class, 'index']);
    Route::post('/links', [ProfessionalLinkBlockController::class, 'store']);
    Route::patch('/links/{linkBlock}', [ProfessionalLinkBlockController::class, 'update'])
        ->whereUuid('linkBlock');
    Route::delete('/links/{linkBlock}', [ProfessionalLinkBlockController::class, 'destroy'])
        ->whereUuid('linkBlock');
    Route::post('/links/reorder', [ProfessionalLinkBlockController::class, 'reorder']);

    // Sections
    Route::get('/sections', [ProfessionalSectionBlockController::class, 'index']);
    Route::put('/sections/{blockType}', [ProfessionalSectionBlockController::class, 'upsert'])
        ->where('blockType', '[a-z0-9_-]+');
    Route::post('/sections/reorder', [ProfessionalSectionBlockController::class, 'reorder']);
    Route::delete('/sections/{blockType}', [ProfessionalSectionBlockController::class, 'remove'])
    ->where('blockType', '[a-z0-9_-]+');

    // Customer View, Add, Edit
    Route::get('/customers', [ProfessionalCustomerController::class, 'index']);
    Route::get('/customers/{customer}', [ProfessionalCustomerController::class, 'show'])
        ->whereUuid('customer');
    Route::post('/customers', [ProfessionalCustomerController::class, 'store']);
    Route::patch('/customers/{customer}', [ProfessionalCustomerController::class, 'update'])
        ->whereUuid('customer');
    Route::delete('/customers/{customer}', [ProfessionalCustomerController::class, 'destroy'])
        ->whereUuid('customer');
    Route::post('/customers/{customer}/restore', [ProfessionalCustomerController::class, 'restore'])
        ->whereUuid('customer')
        ->withTrashed();

    // Theme Selection
    Route::get('/themes', [ProfessionalThemeController::class, 'index']);
    Route::post('/themes/{theme}/select', [ProfessionalThemeController::class, 'select'])
        ->whereUuid('theme');

    // Image Upload
    Route::post('/uploads/prepare', [ProfessionalUploadController::class, 'prepare']);

    // Image Gallery
    Route::get('/gallery', [ProfessionalGalleryController::class, 'index']);
    Route::post('/gallery', [ProfessionalGalleryController::class, 'store']);
    Route::delete('/gallery/{image}', [ProfessionalGalleryController::class, 'destroy'])
        ->whereUuid('image');
    Route::post('/gallery/reorder', [ProfessionalGalleryController::class, 'reorder']);

    // Notifications
    Route::get('/me/notifications', [NotificationController::class, 'index']);
    Route::post('/me/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->whereUuid('notification');
    Route::post('/me/notifications/{notification}/dismiss', [NotificationController::class, 'dismiss'])
        ->whereUuid('notification');

    // Email subscribers (marketing list)
    Route::get('/email-subscribers', [ProfessionalEmailSubscriptionController::class, 'index']);
    Route::get('/email-subscribers/export', [ProfessionalEmailSubscriptionController::class, 'export']);

    });

