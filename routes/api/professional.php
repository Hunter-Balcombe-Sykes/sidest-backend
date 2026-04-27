<?php

use App\Http\Controllers\Api\Professional\AffiliateInviteController;
use App\Http\Controllers\Api\Professional\Analytics\AffiliateCommerceAnalyticsController;
use App\Http\Controllers\Api\Professional\Analytics\BrandCommerceAnalyticsController;
use App\Http\Controllers\Api\Professional\Booking\BookingAnalyticsController;
use App\Http\Controllers\Api\Professional\BrandAffiliateController;
use App\Http\Controllers\Api\Professional\BrandAffiliateInviteController;
use App\Http\Controllers\Api\Professional\BrandGalleryController;
use App\Http\Controllers\Api\Professional\BrandOnboardingReadinessController;
use App\Http\Controllers\Api\Professional\BrandPartnerController;
use App\Http\Controllers\Api\Professional\BrandProfileController;
use App\Http\Controllers\Api\Professional\BrandSetupController;
use App\Http\Controllers\Api\Professional\ConfirmationPreferenceController;
use App\Http\Controllers\Api\Professional\FreshaIntegration\FreshaIntegrationController;
use App\Http\Controllers\Api\Professional\Notifications\NotificationController;
use App\Http\Controllers\Api\Professional\Notifications\NotificationEmailPreferenceController;
use App\Http\Controllers\Api\Professional\Notifications\ProfessionalEmailSubscriptionController;
use App\Http\Controllers\Api\Professional\OpenInviteController;
use App\Http\Controllers\Api\Professional\PlanController;
use App\Http\Controllers\Api\Professional\ProfessionalAccountDeletionController;
use App\Http\Controllers\Api\Professional\ProfessionalAnalyticsController;
use App\Http\Controllers\Api\Professional\ProfessionalController;
use App\Http\Controllers\Api\Professional\ProfessionalCustomerController;
use App\Http\Controllers\Api\Professional\ProfessionalDataExportController;
use App\Http\Controllers\Api\Professional\ProfessionalDocumentController;
use App\Http\Controllers\Api\Professional\ProfessionalEnquiryController;
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
use App\Http\Controllers\Api\Professional\Store\AffiliateProductController;
use App\Http\Controllers\Api\Professional\Store\AffiliateProductPhotoController;
use App\Http\Controllers\Api\Professional\Store\BrandCatalogController;
use App\Http\Controllers\Api\Professional\Store\BrandCollectionController;
use App\Http\Controllers\Api\Professional\Store\BrandDesignController;
use App\Http\Controllers\Api\Professional\Store\BrandStoreSettingsController;
use App\Http\Controllers\Api\Professional\Store\ShopifyResyncController;
use App\Http\Controllers\Api\Professional\Stripe\StripeConnectController;
use App\Http\Controllers\Api\Professional\SubscriptionController;
use App\Http\Controllers\Api\Professional\Uploads\ProfessionalUploadController;
use App\Http\Controllers\Api\PublicSite\SiteVisibilityController;
use App\Http\Middleware\Context\EnforcePendingDeletionReadOnly;
use Illuminate\Support\Facades\Route;

// TODO(v1): all routes in this file should be prefixed /v1/ once frontend is ready for the migration

// Public Plans
Route::get('/plans', [PlanController::class, 'index'])
    ->middleware('throttle:plans');

// Authorised Professional Logged In
Route::middleware(['supabase.jwt', 'current.pro', EnforcePendingDeletionReadOnly::class, 'throttle:authenticated'])
    ->group(function () {

        // Show & Edit Details
        Route::get('/me', [ProfessionalController::class, 'show']);
        Route::patch('/me', [ProfessionalController::class, 'update']);

        // Account Deletion — self-service lifecycle
        Route::prefix('me/deletion')->group(function () {
            Route::post('/request', [ProfessionalAccountDeletionController::class, 'request'])
                ->middleware('throttle:3,60');
            Route::post('/confirm', [ProfessionalAccountDeletionController::class, 'confirm']);
            Route::post('/cancel', [ProfessionalAccountDeletionController::class, 'cancel'])
                ->withoutMiddleware([EnforcePendingDeletionReadOnly::class]);
        });

        // Data export — exempt from EnforcePendingDeletionReadOnly so a
        // professional in their grace period can still pull their data
        // (the whole point of GDPR portability). Rate-limited 1/24h.
        Route::post('/me/data-export', [ProfessionalDataExportController::class, 'store'])
            ->withoutMiddleware([EnforcePendingDeletionReadOnly::class])
            ->middleware('throttle:1,1440');
        Route::get('/brand-affiliates', [BrandAffiliateController::class, 'index']);
        Route::middleware('throttle:30,1')->group(function (): void {
            Route::delete('/brand-affiliates/{affiliate}', [BrandAffiliateController::class, 'disconnect'])
                ->whereUuid('affiliate');
            Route::delete('/brand-partners/{brandProfessionalId}', [BrandPartnerController::class, 'disconnect'])
                ->whereUuid('brandProfessionalId');
        });
        Route::patch('/brand-affiliates/{affiliate}/custom-photos', [BrandAffiliateController::class, 'updateCustomPhotos'])
            ->whereUuid('affiliate');
        Route::get('/brand-affiliates/{affiliate}/snapshot', [BrandAffiliateController::class, 'snapshot'])
            ->whereUuid('affiliate');
        Route::get('/brand-affiliate-invites', [BrandAffiliateInviteController::class, 'index']);
        Route::post('/brand-affiliate-invites/availability', [BrandAffiliateInviteController::class, 'availability']);
        // Write endpoints are gated by BrandFundingGate — a brand can't
        // send invites without a payment method on file (the platform
        // would absorb commission float for any lapsed brand otherwise).
        Route::middleware('brand-funding-gate')->group(function (): void {
            Route::post('/brand-affiliate-invites', [BrandAffiliateInviteController::class, 'store']);
            Route::post('/brand-affiliate-invites/bulk', [BrandAffiliateInviteController::class, 'bulk']);
            Route::post('/brand-affiliate-invites/import-csv', [BrandAffiliateInviteController::class, 'importCsv']);
        });
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
        Route::middleware('feature:smart_booking')->group(function () {
            Route::patch('/booking/settings', [ProfessionalSiteController::class, 'updateBookingSettings']);
            Route::get('/booking/my-analytics/overview', [BookingAnalyticsController::class, 'myOverview']);
        });
        Route::get('/affiliate/commerce-analytics', [AffiliateCommerceAnalyticsController::class, 'overview']);
        Route::get('/brand/commerce-analytics', [BrandCommerceAnalyticsController::class, 'overview']);

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
        Route::post('/sections/reorder', [ProfessionalSectionBlockController::class, 'reorder']);
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

        // Contact section enquiry inbox
        Route::get('/enquiries', [ProfessionalEnquiryController::class, 'index']);
        Route::patch('/enquiries/{id}', [ProfessionalEnquiryController::class, 'update'])
            ->whereUuid('id');
        Route::delete('/enquiries/{id}', [ProfessionalEnquiryController::class, 'destroy'])
            ->whereUuid('id');

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
        Route::delete('/uploads/brand-logo', [ProfessionalUploadController::class, 'destroyBrandLogo']);
        Route::post('/uploads/brand-placeholder-image', [ProfessionalUploadController::class, 'uploadBrandPlaceholderImage']);
        Route::get('/uploads/brand-placeholder-images', [ProfessionalUploadController::class, 'listBrandPlaceholders']);
        Route::post('/uploads/brand-placeholder-images/reorder', [ProfessionalUploadController::class, 'reorderBrandPlaceholders']);
        Route::delete('/uploads/brand-placeholder-images/{media}', [ProfessionalUploadController::class, 'destroyBrandPlaceholder'])
            ->whereUuid('media');

        // Image Management (pool-based: gallery / content)
        Route::get('/images', [ProfessionalUploadController::class, 'index']);
        Route::post('/images/reorder', [ProfessionalUploadController::class, 'reorder']);
        Route::delete('/images/{image}', [ProfessionalUploadController::class, 'destroy'])
            ->whereUuid('image');

        // Image Gallery (gallery-pool ordering & legacy routes)
        Route::get('/gallery', [ProfessionalGalleryController::class, 'index']);
        Route::post('/gallery', [ProfessionalGalleryController::class, 'store']); // deprecated → 410
        Route::patch('/gallery/{image}', [ProfessionalGalleryController::class, 'update'])
            ->whereUuid('image')
            ->middleware('throttle:30,1');
        Route::delete('/gallery/{image}', [ProfessionalGalleryController::class, 'destroy'])
            ->whereUuid('image');
        Route::post('/gallery/reorder', [ProfessionalGalleryController::class, 'reorder']);

        // Documents (one file per site — PDF/JPG/PNG, 10 MB max)
        Route::get('/documents', [ProfessionalDocumentController::class, 'index']);
        Route::post('/documents', [ProfessionalDocumentController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::patch('/documents/{document}', [ProfessionalDocumentController::class, 'update'])
            ->whereUuid('document')
            ->middleware('throttle:30,1');
        Route::delete('/documents/{document}', [ProfessionalDocumentController::class, 'destroy'])
            ->whereUuid('document')
            ->middleware('throttle:30,1');

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

        // Square Integration — gated behind square_sync feature flag
        Route::middleware('feature:square_sync')->group(function () {
            Route::get('/square/status', [SquareIntegrationController::class, 'status']);
            Route::post('/square/connect', [SquareIntegrationController::class, 'connect']);
            Route::post('/square/disconnect', [SquareIntegrationController::class, 'disconnect']);
            Route::get('/square/token', [SquareIntegrationController::class, 'token']);
            Route::post('/square/services/sync', [SquareIntegrationController::class, 'syncServicesNow']);
            Route::post('/square/services/{service}/push', [SquareIntegrationController::class, 'pushServiceNow'])
                ->whereUuid('service');
        });

        // Shopify Integration
        Route::get('/shopify/status', [ShopifyIntegrationController::class, 'status']);
        // Resolve a custom primary domain (e.g. radiorufus.com) or bare handle
        // to the canonical <handle>.myshopify.com used by the OAuth flow.
        // Throttled because it fires an outbound HTTP request on every call.
        Route::get('/shopify/resolve-shop', [ShopifyIntegrationController::class, 'resolveShop'])
            ->middleware('throttle:30,1');
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
        // Temporary diagnostic probe — returns raw Shopify response for a
        // minimal products query so we can see exactly what Shopify returns
        // (shop info, products sample, cost, errors, granted scopes). Safe
        // to leave in place; auth-gated, read-only, no mutations.
        Route::get('/brand/catalog/debug', [BrandCatalogController::class, 'debug']);
        // Re-dispatches the has_enabled_variants backfill — for brands whose
        // first backfill pass hit an earlier bug and marked "complete" with
        // zero writes. Throttled because it kicks off a catalog-wide Shopify
        // read; no reason to let a client spam it.
        Route::post('/brand/catalog/refresh-derived-flags', [BrandCatalogController::class, 'refreshDerivedFlags'])
            ->middleware('throttle:6,1');
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

        // Brand Design. The unified shape lives in site.settings.design and is
        // edited via the standard /site update endpoint — this controller only
        // exposes a read of the resolved shape and a manual Shopify re-sync.
        Route::get('/brand/design', [BrandDesignController::class, 'show']);
        Route::post('/brand/design/resync', [BrandDesignController::class, 'resync'])
            ->middleware('throttle:brand-catalog-writes');

        // Full Shopify data resync (profile + brand fields + logo + theme tokens). Per-integration
        // rate limit is enforced inside the controller (1 per 60s) — that is the primary gate.
        // The shared `brand-catalog-writes` throttle is reused as a secondary safety net; if its
        // definition ever tightens for catalog endpoints this route will inherit the change, which
        // is acceptable because the controller already caps resync traffic more aggressively.
        Route::post('/store/shopify/resync', ShopifyResyncController::class)
            ->middleware('throttle:brand-catalog-writes');

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
        // Per-selection variant picker. Accepts variant_gids=[] or null to reset
        // back to "show every brand-enabled variant"; accepts a populated array
        // to narrow the storefront to exactly those variants.
        Route::patch('/affiliate/selections/{productGid}/variants', [AffiliateProductController::class, 'updateVariants'])
            ->middleware('throttle:affiliate-writes')
            ->where('productGid', '.*');
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

        // Fresha Integration — gated behind fresha_sync feature flag
        Route::middleware('feature:fresha_sync')->group(function () {
            Route::get('/fresha/status', [FreshaIntegrationController::class, 'status']);
            Route::post('/fresha/connect', [FreshaIntegrationController::class, 'connect']);
            Route::post('/fresha/disconnect', [FreshaIntegrationController::class, 'disconnect']);
            Route::get('/fresha/token', [FreshaIntegrationController::class, 'token']);
            Route::post('/fresha/services/sync', [FreshaIntegrationController::class, 'syncServicesNow']);
            Route::post('/fresha/services/{service}/push', [FreshaIntegrationController::class, 'pushServiceNow'])
                ->whereUuid('service');
        });

    });
