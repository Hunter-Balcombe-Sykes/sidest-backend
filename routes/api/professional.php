<?php

use App\Http\Controllers\Api\Professional\AffiliateInviteController;
use App\Http\Controllers\Api\Professional\BrandAffiliateController;
use App\Http\Controllers\Api\Professional\BrandAffiliateInviteController;
use App\Http\Controllers\Api\Professional\BrandGalleryController;
use App\Http\Controllers\Api\Professional\BrandPartnerController;
use App\Http\Controllers\Api\Professional\OpenInviteController;
use App\Http\Controllers\Api\Professional\BrandSetupController;
use App\Http\Controllers\Api\Professional\Booking\BookingAnalyticsController;
use App\Http\Controllers\Api\Professional\ConfirmationPreferenceController;
use App\Http\Controllers\Api\Professional\FreshaIntegration\FreshaIntegrationController;
use App\Http\Controllers\Api\Professional\Notifications\NotificationController;
use App\Http\Controllers\Api\Professional\Notifications\NotificationEmailPreferenceController;
use App\Http\Controllers\Api\Professional\Notifications\ProfessionalEmailSubscriptionController;
use App\Http\Controllers\Api\Professional\PlanController;
use App\Http\Controllers\Api\Professional\ProfessionalAnalyticsController;
use App\Http\Controllers\Api\Professional\ProfessionalController;
use App\Http\Controllers\Api\Professional\ProfessionalCustomerController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalGalleryController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalGoogleBusinessProfileController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalLinkBlockController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalSectionBlockController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalServiceCategoryController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalServiceController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalSiteController;
use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalThemeController;
use App\Http\Controllers\Api\Professional\ShopifyIntegration\ShopifyIntegrationController;
use App\Http\Controllers\Api\Professional\SquareIntegration\SquareIntegrationController;
use App\Http\Controllers\Api\Professional\BrandOnboardingReadinessController;
use App\Http\Controllers\Api\Professional\BrandProfileController;
use App\Http\Controllers\Api\Professional\Stripe\StripeConnectController;
use App\Http\Controllers\Api\Professional\SubscriptionController;
use App\Http\Controllers\Api\Professional\Uploads\ProfessionalUploadController;
use App\Http\Controllers\Api\Professional\Store\AffiliateProductController;
use App\Http\Controllers\Api\Professional\Store\AffiliateProductPhotoController;
use App\Http\Controllers\Api\Professional\Store\BrandCatalogController;
use App\Http\Controllers\Api\Professional\Store\BrandCollectionController;
use App\Http\Controllers\Api\Professional\Store\BrandDesignController;
use App\Http\Controllers\Api\Professional\Store\BrandStoreSettingsController;
use App\Http\Controllers\Api\PublicSite\SiteVisibilityController;
use Illuminate\Support\Facades\Route;

// Public Plans
Route::get('/plans', [PlanController::class, 'index'])
    ->middleware('throttle:plans');

// Authorised Professional Logged In
Route::middleware(['supabase.jwt', 'current.pro', 'throttle:authenticated'])
    ->group(function () {

        // Show & Edit Details
        Route::get('/me', [ProfessionalController::class, 'show']);
        Route::patch('/me', [ProfessionalController::class, 'update']);
        Route::get('/brand-affiliates', [BrandAffiliateController::class, 'index']);
        Route::delete('/brand-affiliates/{affiliate}', [BrandAffiliateController::class, 'disconnect'])
            ->whereUuid('affiliate');
        Route::patch('/brand-affiliates/{affiliate}/custom-photos', [BrandAffiliateController::class, 'updateCustomPhotos'])
            ->whereUuid('affiliate');
        Route::get('/brand-affiliate-invites', [BrandAffiliateInviteController::class, 'index']);
        Route::post('/brand-affiliate-invites/availability', [BrandAffiliateInviteController::class, 'availability']);
        Route::post('/brand-affiliate-invites', [BrandAffiliateInviteController::class, 'store']);
        Route::post('/brand-affiliate-invites/bulk', [BrandAffiliateInviteController::class, 'bulk']);
        Route::post('/brand-affiliate-invites/import-csv', [BrandAffiliateInviteController::class, 'importCsv']);
        Route::delete('/brand-affiliate-invites/{invite}', [BrandAffiliateInviteController::class, 'destroy'])
            ->whereUuid('invite');
        Route::post('/brand-affiliate-invites/{token}/claim', [BrandAffiliateInviteController::class, 'claim']);
        Route::post('/brand-affiliate-invites/{token}/decline', [BrandAffiliateInviteController::class, 'decline']);
        Route::get('/affiliate-invites', [AffiliateInviteController::class, 'index']);
        Route::post('/join/{handle}', [OpenInviteController::class, 'claim'])
            ->where('handle', '[A-Za-z0-9][A-Za-z0-9_-]*')
            ->middleware('throttle:affiliate-writes');
        Route::get('/brand-partners', [BrandPartnerController::class, 'index']);
        Route::post('/brand-partners/{brandProfessionalId}/connect', [BrandPartnerController::class, 'connect'])
            ->whereUuid('brandProfessionalId');
        Route::post('/brand-partners/{brandProfessionalId}/promote', [BrandPartnerController::class, 'promote'])
            ->whereUuid('brandProfessionalId');
        Route::delete('/brand-partners/{brandProfessionalId}', [BrandPartnerController::class, 'disconnect'])
            ->whereUuid('brandProfessionalId');

        // View Site Details
        Route::get('/site', [ProfessionalSiteController::class, 'show']);
        Route::get('/site/google-business-profile', [ProfessionalGoogleBusinessProfileController::class, 'show']);

        // Update Site Details
        Route::patch('/site', [ProfessionalSiteController::class, 'update']);
        Route::put('/site/google-business-profile', [ProfessionalGoogleBusinessProfileController::class, 'upsert']);
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
        Route::get('/booking/my-analytics/overview', [BookingAnalyticsController::class, 'myOverview']);

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

        // UI Confirmation Preferences ("don't ask again" toggles)
        Route::get('/confirmation-preferences', [ConfirmationPreferenceController::class, 'show']);
        Route::patch('/confirmation-preferences', [ConfirmationPreferenceController::class, 'update']);

        // Theme Selection
        Route::get('/themes', [ProfessionalThemeController::class, 'index']);
        Route::post('/themes/{theme}/select', [ProfessionalThemeController::class, 'select'])
            ->whereUuid('theme');

        // Image Upload (server-side processing → WebP variants via queue)
        Route::post('/uploads', [ProfessionalUploadController::class, 'upload']);
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

        // Notification email preferences
        Route::get('/me/notification-email-preferences', [NotificationEmailPreferenceController::class, 'index']);
        Route::patch('/me/notification-email-preferences', [NotificationEmailPreferenceController::class, 'update']);

        // Email subscribers (marketing list)
        Route::get('/email-subscribers', [ProfessionalEmailSubscriptionController::class, 'index']);
        Route::get('/email-subscribers/export', [ProfessionalEmailSubscriptionController::class, 'export']);

        // Subscription & Plans
        Route::get('/me/subscription', [SubscriptionController::class, 'show']);
        Route::post('/me/subscription', [SubscriptionController::class, 'store']);
        Route::patch('/me/subscription', [SubscriptionController::class, 'update']);
        Route::post('/me/subscription/cancel', [SubscriptionController::class, 'cancel']);
        Route::post('/me/subscription/resume', [SubscriptionController::class, 'resume']);
        Route::post('/me/subscription/billing-portal', [SubscriptionController::class, 'billingPortal']);
        Route::get('/me/subscription/preview-change', [SubscriptionController::class, 'previewPlanChange']);

        // Square Integration
        Route::get('/square/status', [SquareIntegrationController::class, 'status']);
        Route::post('/square/connect', [SquareIntegrationController::class, 'connect']);
        Route::post('/square/disconnect', [SquareIntegrationController::class, 'disconnect']);
        Route::get('/square/token', [SquareIntegrationController::class, 'token']);
        Route::post('/square/services/sync', [SquareIntegrationController::class, 'syncServicesNow']);
        Route::post('/square/services/{service}/push', [SquareIntegrationController::class, 'pushServiceNow'])
            ->whereUuid('service');

        // Shopify Integration
        Route::get('/shopify/status', [ShopifyIntegrationController::class, 'status']);
        Route::post('/shopify/connect', [ShopifyIntegrationController::class, 'connect']);
        Route::post('/shopify/disconnect', [ShopifyIntegrationController::class, 'disconnect']);
        Route::get('/shopify/token', [ShopifyIntegrationController::class, 'token']);
        Route::post('/shopify/webhooks/register', [ShopifyIntegrationController::class, 'registerWebhooks']);

        // Brand profile (business fields)
        Route::get('/brand/profile', [BrandProfileController::class, 'show']);
        Route::patch('/brand/profile', [BrandProfileController::class, 'update']);

        // Brand Gallery Fallback
        Route::get('/brand/gallery', [BrandGalleryController::class, 'index']);
        Route::post('/brand/gallery', [BrandGalleryController::class, 'upload'])
            ->middleware('throttle:brand-catalog-writes');
        Route::delete('/brand/gallery/{media}', [BrandGalleryController::class, 'destroy'])
            ->whereUuid('media')
            ->middleware('throttle:brand-catalog-writes');
        Route::patch('/brand/gallery/reorder', [BrandGalleryController::class, 'reorder'])
            ->middleware('throttle:brand-catalog-writes');

        // Brand onboarding readiness
        Route::get('/brand/onboarding-readiness', [BrandOnboardingReadinessController::class, 'show']);

        // Brand setup wizard
        Route::get('/brand/setup/status', [BrandSetupController::class, 'setupStatus']);
        Route::post('/brand/setup/complete', [BrandSetupController::class, 'completeSetup']);

        // Brand Catalog Management
        Route::get('/brand/catalog', [BrandCatalogController::class, 'index']);
        Route::get('/brand/catalog/all', [BrandCatalogController::class, 'all']);
        Route::patch('/brand/catalog/{productGid}/metafields', [BrandCatalogController::class, 'updateMetafields'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');
        Route::patch('/brand/catalog/{productGid}/active', [BrandCatalogController::class, 'toggleActive'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');
        Route::patch('/brand/catalog/{productGid}/commission', [BrandCatalogController::class, 'updateCommission'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');
        Route::patch('/brand/catalog/{productGid}/discount', [BrandCatalogController::class, 'updateDiscount'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');

        // Brand Store Settings
        Route::get('/brand/store-settings', [BrandStoreSettingsController::class, 'show']);
        Route::patch('/brand/store-settings', [BrandStoreSettingsController::class, 'update'])
            ->middleware('throttle:brand-catalog-writes');

        // Brand Design (Shopify theme sync + sitepage overrides)
        Route::get('/brand/design', [BrandDesignController::class, 'show']);
        Route::post('/brand/design/resync', [BrandDesignController::class, 'resync'])
            ->middleware('throttle:brand-catalog-writes');
        Route::patch('/brand/design/overrides', [BrandDesignController::class, 'updateOverrides'])
            ->middleware('throttle:brand-catalog-writes');
        Route::delete('/brand/design/overrides/{token}', [BrandDesignController::class, 'resetOverride'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('token', '[a-z_]+');

        // Brand Collection Management
        Route::get('/brand/collections/{collectionType}/products', [BrandCollectionController::class, 'index'])
            ->where('collectionType', 'active|default|favourites');
        Route::post('/brand/collections/{collectionType}/products', [BrandCollectionController::class, 'addProducts'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('collectionType', 'active|default|favourites');
        Route::delete('/brand/collections/{collectionType}/products', [BrandCollectionController::class, 'removeProducts'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('collectionType', 'active|default|favourites');

        // Stripe Connect & Payouts
        Route::get('/stripe/status', [StripeConnectController::class, 'status']);
        Route::post('/stripe/connect/onboard', [StripeConnectController::class, 'onboard']);
        Route::post('/stripe/connect/dashboard', [StripeConnectController::class, 'dashboard']);
        Route::post('/stripe/connect/disconnect', [StripeConnectController::class, 'disconnect']);
        Route::patch('/stripe/funding-mode', [StripeConnectController::class, 'updateFundingMode']);
        Route::post('/stripe/payment-method/setup', [StripeConnectController::class, 'setupPaymentMethod']);
        Route::post('/stripe/payment-method/setup-checkout', [StripeConnectController::class, 'createPaymentMethodCheckoutSession']);
        Route::post('/stripe/payment-method/confirm', [StripeConnectController::class, 'confirmPaymentMethod']);
        Route::post('/stripe/payment-method/sync-session', [StripeConnectController::class, 'syncPaymentMethodSession']);
        Route::get('/stripe/payment-methods', [StripeConnectController::class, 'listPaymentMethods']);
        Route::delete('/stripe/payment-method', [StripeConnectController::class, 'removePaymentMethod']);
        Route::post('/stripe/topups/checkout', [StripeConnectController::class, 'createTopUpCheckoutSession']);
        Route::post('/stripe/topups/confirm', [StripeConnectController::class, 'confirmTopUpCheckoutSession']);
        Route::get('/stripe/payouts', [StripeConnectController::class, 'payouts']);

        // Affiliate Product Selections
        Route::get('/affiliate/products', [AffiliateProductController::class, 'index']);
        Route::get('/affiliate/selections/stale', [AffiliateProductController::class, 'stale']);
        Route::post('/affiliate/selections', [AffiliateProductController::class, 'store'])
            ->middleware('throttle:affiliate-writes');
        Route::delete('/affiliate/selections/{gid}', [AffiliateProductController::class, 'destroy'])
            ->middleware('throttle:affiliate-writes')
            ->where('gid', '.*');
        Route::patch('/affiliate/selections/reorder', [AffiliateProductController::class, 'reorder'])
            ->middleware('throttle:affiliate-writes');
        Route::post('/affiliate/selections/reset-to-defaults', [AffiliateProductController::class, 'resetToDefaults'])
            ->middleware('throttle:affiliate-writes');

        // Affiliate Custom Product Photos
        Route::get('/affiliate/products/{gid}/photos', [AffiliateProductPhotoController::class, 'index'])
            ->where('gid', '.*');
        Route::post('/affiliate/products/{gid}/photos', [AffiliateProductPhotoController::class, 'upload'])
            ->where('gid', '.*')
            ->middleware('throttle:affiliate-writes');
        Route::delete('/affiliate/products/{gid}/photos/{media}', [AffiliateProductPhotoController::class, 'destroy'])
            ->where('gid', '.*')
            ->whereUuid('media')
            ->middleware('throttle:affiliate-writes');
        Route::patch('/affiliate/products/{gid}/photos/reorder', [AffiliateProductPhotoController::class, 'reorder'])
            ->where('gid', '.*')
            ->middleware('throttle:affiliate-writes');

        // Fresha Integration
        Route::get('/fresha/status', [FreshaIntegrationController::class, 'status']);
        Route::post('/fresha/connect', [FreshaIntegrationController::class, 'connect']);
        Route::post('/fresha/disconnect', [FreshaIntegrationController::class, 'disconnect']);
        Route::get('/fresha/token', [FreshaIntegrationController::class, 'token']);
        Route::post('/fresha/services/sync', [FreshaIntegrationController::class, 'syncServicesNow']);
        Route::post('/fresha/services/{service}/push', [FreshaIntegrationController::class, 'pushServiceNow'])
            ->whereUuid('service');

    });
