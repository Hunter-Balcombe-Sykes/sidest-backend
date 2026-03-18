<?php

use App\Http\Controllers\Api\Professional\Notifications\NotificationController;
use App\Http\Controllers\Api\Professional\PlanController;
use App\Http\Controllers\Api\Professional\ProfessionalAnalyticsController;
use App\Http\Controllers\Api\Professional\BrandAffiliateController;
use App\Http\Controllers\Api\Professional\BrandAffiliateInviteController;
use App\Http\Controllers\Api\Professional\BrandPartnerController;
use App\Http\Controllers\Api\Professional\ProfessionalController;
use App\Http\Controllers\Api\Professional\ProfessionalCustomerController;
use App\Http\Controllers\Api\Professional\SubscriptionController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalLinkBlockController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalSectionBlockController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalServiceController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalServiceCategoryController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalSiteController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalThemeController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalGalleryController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalGoogleBusinessProfileController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalLegalContentController;
use App\Http\Controllers\Api\Professional\Uploads\ProfessionalUploadController;
use App\Http\Controllers\Api\Professional\Notifications\ProfessionalEmailSubscriptionController;
use App\Http\Controllers\Api\Professional\SquareIntegration\SquareIntegrationController;
use App\Http\Controllers\Api\Professional\FreshaIntegration\FreshaIntegrationController;
use App\Http\Controllers\Api\Professional\Store\BrandStoreController;
use App\Http\Controllers\Api\Professional\Store\FeaturedProductsController;
use App\Http\Controllers\Api\PublicSite\SiteVisibilityController;
use Illuminate\Support\Facades\Route;

// Public Plans
Route::get('/plans', [PlanController::class, 'index']);

// Authorised Professional Logged In
Route::middleware(['supabase.jwt', 'current.pro'])
    ->group(function () {

    // Show & Edit Details
    Route::get('/me', [ProfessionalController::class, 'show']);
    Route::patch('/me', [ProfessionalController::class, 'update']);
    Route::get('/brand-affiliates', [BrandAffiliateController::class, 'index']);
    Route::delete('/brand-affiliates/{affiliate}', [BrandAffiliateController::class, 'disconnect']);
    Route::get('/brand-affiliate-invites', [BrandAffiliateInviteController::class, 'index']);
    Route::post('/brand-affiliate-invites/availability', [BrandAffiliateInviteController::class, 'availability']);
    Route::post('/brand-affiliate-invites', [BrandAffiliateInviteController::class, 'store']);
    Route::delete('/brand-affiliate-invites/{invite}', [BrandAffiliateInviteController::class, 'destroy']);
    Route::post('/brand-affiliate-invites/{token}/claim', [BrandAffiliateInviteController::class, 'claim']);
    Route::post('/brand-affiliate-invites/{token}/decline', [BrandAffiliateInviteController::class, 'decline']);
    Route::get('/brand-partners', [BrandPartnerController::class, 'index']);
    Route::post('/brand-partners/{brandProfessionalId}/promote', [BrandPartnerController::class, 'promote'])
        ->whereUuid('brandProfessionalId');
    Route::delete('/brand-partners/{brandProfessionalId}', [BrandPartnerController::class, 'disconnect'])
        ->whereUuid('brandProfessionalId');

    // View Site Details
    Route::get('/site', [ProfessionalSiteController::class, 'show']);
    Route::get('/site/google-business-profile', [ProfessionalGoogleBusinessProfileController::class, 'show']);
    Route::get('/site/legal-content', [ProfessionalLegalContentController::class, 'show']);

    // Update Site Details
    Route::patch('/site', [ProfessionalSiteController::class, 'update']);
    Route::put('/site/google-business-profile', [ProfessionalGoogleBusinessProfileController::class, 'upsert']);
    Route::put('/site/legal-content', [ProfessionalLegalContentController::class, 'upsert']);
    Route::patch('/site/legal-content', [ProfessionalLegalContentController::class, 'upsert']);
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

    // Service Categories (CRUD + reorder)
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

    // Image Upload (server-side processing → WebP variants via queue)
    Route::post('/uploads', [ProfessionalUploadController::class, 'upload']);
    Route::post('/uploads/brand-font', [ProfessionalUploadController::class, 'uploadBrandFont']);
    Route::post('/uploads/brand-logo', [ProfessionalUploadController::class, 'uploadBrandLogo']);
    Route::post('/uploads/brand-placeholder-image', [ProfessionalUploadController::class, 'uploadBrandPlaceholderImage']);

    // Image Management (pool-based: gallery / content)
    Route::get('/images', [ProfessionalUploadController::class, 'index']);
    Route::post('/images/reorder', [ProfessionalUploadController::class, 'reorder']);
    Route::delete('/images/{image}', [ProfessionalUploadController::class, 'destroy'])
        ->whereUuid('image');

    // Image Gallery (gallery-pool ordering & legacy routes)
    Route::get('/gallery', [ProfessionalGalleryController::class, 'index']);
    Route::post('/gallery', [ProfessionalGalleryController::class, 'store']); // deprecated → 410
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

    // Subscription & Plans
    Route::get('/me/subscription', [SubscriptionController::class, 'show']);
    Route::post('/me/subscription', [SubscriptionController::class, 'store']);
    Route::patch('/me/subscription', [SubscriptionController::class, 'update']);
    Route::post('/me/subscription/cancel', [SubscriptionController::class, 'cancel']);
    Route::post('/me/subscription/resume', [SubscriptionController::class, 'resume']);

    // Square Integration
    Route::get('/square/status', [SquareIntegrationController::class, 'status']);
    Route::post('/square/connect', [SquareIntegrationController::class, 'connect']);
    Route::post('/square/disconnect', [SquareIntegrationController::class, 'disconnect']);
    Route::get('/square/token', [SquareIntegrationController::class, 'token']);
    Route::post('/square/services/sync', [SquareIntegrationController::class, 'syncServicesNow']);
    Route::post('/square/services/{service}/push', [SquareIntegrationController::class, 'pushServiceNow'])
        ->whereUuid('service');

    // Store: Featured Products
    Route::get('/store/featured-products', [FeaturedProductsController::class, 'index']);
    Route::put('/store/featured-products', [FeaturedProductsController::class, 'update']);

    // Store: Brand Settings & Per-Product Settings
    Route::get('/store/brand-settings', [BrandStoreController::class, 'index']);
    Route::patch('/store/brand-settings', [BrandStoreController::class, 'updateSettings']);
    Route::put('/store/brand-product-settings', [BrandStoreController::class, 'updateProductSettings']);

    // Fresha Integration
    Route::get('/fresha/status', [FreshaIntegrationController::class, 'status']);
    Route::post('/fresha/connect', [FreshaIntegrationController::class, 'connect']);
    Route::post('/fresha/disconnect', [FreshaIntegrationController::class, 'disconnect']);
    Route::get('/fresha/token', [FreshaIntegrationController::class, 'token']);
    Route::post('/fresha/services/sync', [FreshaIntegrationController::class, 'syncServicesNow']);
    Route::post('/fresha/services/{service}/push', [FreshaIntegrationController::class, 'pushServiceNow'])
        ->whereUuid('service');

    });
