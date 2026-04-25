<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffAffiliateController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffAffiliateStatusController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandProfileController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionPayoutController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionVoidController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCustomerManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffIntegrationController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffInviteController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffLinkBlockManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffProfessionalController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffSectionManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffServiceCategoryManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffServiceManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffShopifyResyncController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffSiteManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffStoreSettingsController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffSubscriptionManagementController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffAccountDeletionController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffAnalyticsController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffMeController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationEmailPolicyController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffSiteController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffStatsController;
use Illuminate\Support\Facades\Route;

// TODO(v1): all routes in this file should be prefixed /v1/ once frontend is ready for the migration

// Authorised Staff Viewing
Route::prefix('staff')
    ->middleware(['supabase.jwt', 'staff', 'throttle:staff'])
    ->whereUuid('professional')
    ->scopeBindings()
    ->group(function () {

        // Staff Dashboard
        Route::get('/me', [StaffMeController::class, 'show']);

        // Platform-wide stats
        Route::get('/stats', [StaffStatsController::class, 'show']);

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

        // View affiliates linked to a brand
        Route::get('/professionals/{professional}/affiliates', [StaffAffiliateController::class, 'index']);

        // View commission ledger for a professional (brand or affiliate)
        Route::get('/professionals/{professional}/commissions', [StaffCommissionController::class, 'index']);

        // List all payouts platform-wide
        Route::get('/commission-payouts', [StaffCommissionPayoutController::class, 'index']);

        // View integration status for a professional
        Route::get('/professionals/{professional}/integrations', [StaffIntegrationController::class, 'index']);

        // View invites for a brand
        Route::get('/professionals/{professional}/invites', [StaffInviteController::class, 'index']);

        // View account deletion state + audit log for support context.
        // withTrashed: support may need to view erasure history of an account
        // that has already been soft-deleted by a regular staff destroy.
        Route::get('/professionals/{professional}/deletion', [StaffAccountDeletionController::class, 'show'])
            ->withTrashed();
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
        Route::delete('/professionals/{professional}/force', [StaffProfessionalController::class, 'forceDestroy']);

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

        // Commission payout admin — manually retry stuck failed batches. Top-level
        // because a payout has both a brand and an affiliate; scoping to one
        // professional would be misleading.
        Route::post('/commission-payouts/{payout}/retry', [StaffCommissionPayoutController::class, 'retry'])
            ->whereUuid('payout');

        // Expire a stuck invite (admin only)
        Route::delete('/professionals/{professional}/invites/{invite}', [StaffInviteController::class, 'cancel'])
            ->whereUuid('invite');

        // Edit brand profile (admin only)
        Route::patch('/professionals/{professional}/brand-profile', [StaffBrandProfileController::class, 'update']);

        // Toggle affiliate status for a brand (admin only)
        Route::patch('/professionals/{professional}/affiliates/{affiliate}/status', [StaffAffiliateStatusController::class, 'update'])
            ->whereUuid('affiliate');

        // Manually void a pending commission entry (admin only)
        Route::post('/commissions/{commission}/void', [StaffCommissionVoidController::class, 'void'])
            ->whereUuid('commission');

        // Trigger Shopify resync for a brand (admin only)
        Route::post('/professionals/{professional}/integrations/shopify/resync', [StaffShopifyResyncController::class, 'invoke']);

        // Override brand commission rate and payout hold days (admin only)
        Route::patch('/professionals/{professional}/store-settings', [StaffStoreSettingsController::class, 'update']);

        // GDPR-triggered erasure: support invokes the same lifecycle as self-service
        // but skips the email-token step. Reason field is mandatory.
        // withTrashed: an already-soft-deleted account can still be the subject
        // of an Article 17 request and must be reachable via this endpoint.
        Route::post('/professionals/{professional}/deletion/initiate', [StaffAccountDeletionController::class, 'initiate'])
            ->withTrashed();
        Route::post('/professionals/{professional}/deletion/cancel', [StaffAccountDeletionController::class, 'cancel'])
            ->withTrashed();

        // Manually create or remove brand-affiliate links (admin only).
        // withoutScopedBindings(): {brand} and {affiliate} are two independent
        // Professional models — not parent/child. scopeBindings() would try to
        // call $brand->affiliates() which doesn't exist, causing a 500.
        Route::middleware('throttle:30,1')->withoutScopedBindings()->group(function (): void {
            Route::post('/professionals/{brand}/affiliates/{affiliate}',
                [\App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandAffiliateLinkController::class, 'store'])
                ->whereUuid(['brand', 'affiliate']);

            Route::delete('/professionals/{brand}/affiliates/{affiliate}',
                [\App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandAffiliateLinkController::class, 'destroy'])
                ->whereUuid(['brand', 'affiliate']);
        });
    });
