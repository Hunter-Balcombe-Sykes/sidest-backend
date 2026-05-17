<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffAffiliateController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffAffiliateStatusController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandProfileController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionAdjustmentController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionPayoutController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionVoidController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCustomerManagementController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffDataExportController;
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
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffStripeConnectController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffSubscriptionManagementController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffAccountDeletionController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffAffiliateSelectionController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffAnalyticsController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffBookingController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffBrandCatalogController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffBrandCollectionController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffBrandCommerceAnalyticsController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffBrandDesignController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffBrandSetupController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffEmailSubscriberController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffEnquiryController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffFreshaController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffGoogleBusinessProfileController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffMeController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationEmailPolicyController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffSiteController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffSquareController;
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

        // Preview a plan change (proration) — read-only mirror of self-service.
        Route::get('/professionals/{professional}/subscription/preview-change', [StaffSubscriptionManagementController::class, 'previewChange']);

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

        // View a pro's in-app notifications (read-only mirror of /me/notifications)
        // so support can see exactly which banners the brand is staring at.
        Route::get('/professionals/{professional}/notifications', [StaffNotificationController::class, 'indexForProfessional']);

        // View account deletion state + audit log for support context.
        // withTrashed: support may need to view erasure history of an account
        // that has already been soft-deleted by a regular staff destroy.
        Route::get('/professionals/{professional}/deletion', [StaffAccountDeletionController::class, 'show'])
            ->withTrashed();

        // Data export — staff-triggered. ?send_to=staff requires admin role
        // (enforced in the controller). Same 30-min dedup window as self-service.
        Route::post('/professionals/{professional}/data-export', [StaffDataExportController::class, 'store']);

        // Integration status — read-only mirrors of the brand-facing status endpoints.
        // Gated behind the same feature flag as the self-service routes so staff can't
        // see status for an integration the platform doesn't expose yet.
        Route::middleware('feature:square_sync')->group(function () {
            Route::get('/professionals/{professional}/square/status', [StaffSquareController::class, 'status']);
        });
        Route::middleware('feature:fresha_sync')->group(function () {
            Route::get('/professionals/{professional}/fresha/status', [StaffFreshaController::class, 'status']);
        });

        // ── B2: Read-only inspector mirrors ──────────────────────────────────
        // Every route below is a "GET endpoint exposing the same payload the
        // brand sees" — any-staff read, no admin gate. Audit: B2 bundle in
        // audits/open/audit-2026-05-08-staff-admin-coverage.md.

        // #GDPR-1 — email subscribers list + CSV export. Compliance: Article 15/20
        // requests routed to Partna support need a way to answer without the brand.
        Route::get('/professionals/{professional}/email-subscribers', [StaffEmailSubscriberController::class, 'index']);
        Route::get('/professionals/{professional}/email-subscribers/export', [StaffEmailSubscriberController::class, 'export']);

        // #ENQUIRY-1 — contact-form enquiries inbox (read).
        Route::get('/professionals/{professional}/enquiries', [StaffEnquiryController::class, 'index']);

        // #BRAND-SETUP-1 — onboarding readiness + setup-wizard status.
        Route::get('/professionals/{professional}/brand/onboarding-readiness', [StaffBrandSetupController::class, 'readiness']);
        Route::get('/professionals/{professional}/brand/setup/status', [StaffBrandSetupController::class, 'setupStatus']);

        // #BRAND-DESIGN-1 — resolved brand design shape (logo, tokens, theme mode).
        Route::get('/professionals/{professional}/brand/design', [StaffBrandDesignController::class, 'show']);

        // #COLLECTION-1 — products in a brand's Shopify collection (active|default|favourites).
        Route::get('/professionals/{professional}/brand/collections/{collectionType}/products', [StaffBrandCollectionController::class, 'index'])
            ->where('collectionType', 'active|default|favourites');

        // #GBP-1 — Google Business Profile snapshot stored in site.settings.
        Route::get('/professionals/{professional}/site/google-business-profile', [StaffGoogleBusinessProfileController::class, 'show']);

        // #BOOK-1 — booking settings + smart-mode analytics. Settings read works
        // even when smart_booking is off; analytics returns smart_mode_required:true
        // for non-smart brands (mirroring the brand-side behaviour).
        Route::get('/professionals/{professional}/booking/settings', [StaffBookingController::class, 'settings']);
        Route::middleware('feature:smart_booking')->group(function () {
            Route::get('/professionals/{professional}/booking/analytics', [StaffBookingController::class, 'analytics']);
        });

        // #ANALYTICS-1 — brand commerce overview. Delegates to the brand controller
        // so the cache key is shared (per CLAUDE.md commerce-read pattern).
        Route::get('/professionals/{professional}/commerce-analytics', [StaffBrandCommerceAnalyticsController::class, 'overview']);

        // #CATALOG-1 — Shopify catalog inspector. Never expose a ?fresh=true flag
        // here — that would let a forgotten admin tab burn the brand's Shopify
        // rate-limit budget. Same TTL, same single-flight lock as the brand side.
        Route::get('/professionals/{professional}/brand/catalog', [StaffBrandCatalogController::class, 'index']);
        Route::get('/professionals/{professional}/brand/catalog/all', [StaffBrandCatalogController::class, 'all']);
        Route::get('/professionals/{professional}/brand/catalog/debug', [StaffBrandCatalogController::class, 'debug']);

        // #STRIPE-PM-1 — Stripe payment methods (brand-only data, last4+brand) and
        // payouts list across both brand and affiliate roles for the inspected pro.
        // #PAYOUT-1 — curated Connect status read for support triage on stuck payouts.
        Route::get('/professionals/{professional}/stripe/payment-methods', [StaffStripeConnectController::class, 'paymentMethods']);
        Route::get('/professionals/{professional}/stripe/payouts', [StaffStripeConnectController::class, 'payouts']);
        Route::get('/professionals/{professional}/stripe/status', [StaffStripeConnectController::class, 'status']);

        // #AFF-SEL-1 (read part) — affiliate selections inspector. The reset-to-defaults
        // POST is an admin write and is intentionally not included in B2.
        Route::get('/professionals/{professional}/affiliate/selections', [StaffAffiliateSelectionController::class, 'index']);

        // B6 #AFF-PHOTO-1 (read part) — inspect affiliate custom product photos (any-staff).
        // Admin-only delete lives in the admin group below.
        Route::get('/professionals/{professional}/affiliate/products/{gid}/photos',
            [\App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffAffiliatePhotoController::class, 'index'])
            ->where('gid', 'gid://shopify/Product/[0-9]+');
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

        // Bulk suspend/reactivate a wave of professionals (compliance sweep, admin only).
        // Cap 100 IDs is enforced by form rules; 5/min throttle here is the rate limit.
        Route::middleware('throttle:5,1')
            ->post('/professionals/bulk-status', [StaffProfessionalController::class, 'bulkUpdateStatus']);

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

        // Mint a Stripe billing-portal session and email it to the brand. The
        // URL is intentionally NOT returned to staff — only the account holder
        // receives the link (NotificationPublisher → SubscriptionMail).
        Route::post('/professionals/{professional}/subscription/billing-portal', [StaffSubscriptionManagementController::class, 'billingPortal']);

        // Notifications
        Route::post('/notifications', [StaffNotificationController::class, 'store']);

        // Clear stuck banners on a brand's dashboard — staff-on-behalf-of writes
        // to the same notification_receipts table the self-service endpoints use.
        // withoutScopedBindings(): Notification.professional_id is nullable
        // (global broadcasts), so we can't resolve {notification} as a child of
        // {professional}. The controller asserts visibility manually.
        Route::withoutScopedBindings()->group(function (): void {
            Route::post('/professionals/{professional}/notifications/{notification}/read', [StaffNotificationController::class, 'markReadForProfessional'])
                ->whereUuid('notification');
            Route::post('/professionals/{professional}/notifications/{notification}/dismiss', [StaffNotificationController::class, 'dismissForProfessional'])
                ->whereUuid('notification');
        });

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
        // Acknowledge a manual Stripe refund after double-failure (transfer failed + auto-refund failed).
        // Staff must call this before /retry is unblocked for the payout.
        Route::post('/commission-payouts/{payout}/acknowledge-manual-refund', [StaffCommissionPayoutController::class, 'acknowledgeManualRefund'])
            ->whereUuid('payout');

        // Expire a stuck invite (admin only)
        Route::delete('/professionals/{professional}/invites/{invite}', [StaffInviteController::class, 'cancel'])
            ->whereUuid('invite');

        // Send invites on a brand's behalf (INVITE-1) — for support rescuing a
        // brand stuck on CSV import or a 200-affiliate launch. Brand-only +
        // funding-gated inline in the controller (the JWT is staff here, so the
        // `brand.only` and `brand-funding-gate` middlewares used self-service
        // would short-circuit as no-ops).
        Route::post('/professionals/{professional}/invites', [StaffInviteController::class, 'store']);
        Route::post('/professionals/{professional}/invites/bulk', [StaffInviteController::class, 'bulk']);
        Route::post('/professionals/{professional}/invites/import-csv', [StaffInviteController::class, 'importCsv']);
        Route::post('/professionals/{professional}/invites/{invite}/resend', [StaffInviteController::class, 'resend'])
            ->whereUuid('invite');

        // Edit brand profile (admin only)
        Route::patch('/professionals/{professional}/brand-profile', [StaffBrandProfileController::class, 'update']);

        // Toggle affiliate status for a brand (admin only)
        Route::patch('/professionals/{professional}/affiliates/{affiliate}/status', [StaffAffiliateStatusController::class, 'update'])
            ->whereUuid('affiliate');

        // Manually void a pending commission entry (admin only)
        Route::post('/commissions/{commission}/void', [StaffCommissionVoidController::class, 'void'])
            ->whereUuid('commission');

        // LEDGER-1 — post a manual commission adjustment row (admin only). Idempotent
        // on the caller-supplied {reference}. Touches commerce.commission_movements
        // directly, so the audit trail (reason + actor) is baked into calculation_metadata.
        Route::post('/commissions/adjust', [StaffCommissionAdjustmentController::class, 'store']);

        // Trigger Shopify resync for a brand (admin only)
        Route::post('/professionals/{professional}/integrations/shopify/resync', [StaffShopifyResyncController::class, 'invoke']);

        // Sever a stale Shopify connection on a brand's behalf. Runs the same teardown +
        // local cleanup the brand-facing disconnect performs, via ShopifyDisconnectService.
        Route::post('/professionals/{professional}/integrations/shopify/disconnect', [StaffShopifyResyncController::class, 'disconnect']);

        // Re-arm order webhooks after drift (topic-version bump, manual delete in Shopify
        // admin). Dispatches the same RegisterShopifyWebhooksJob the brand endpoint uses.
        Route::post('/professionals/{professional}/integrations/shopify/register-webhooks', [StaffShopifyResyncController::class, 'registerWebhooks']);

        // Square / Fresha force-disconnect — admin write, feature-gated to match self-service.
        Route::middleware('feature:square_sync')->group(function () {
            Route::post('/professionals/{professional}/square/disconnect', [StaffSquareController::class, 'disconnect']);
        });
        Route::middleware('feature:fresha_sync')->group(function () {
            Route::post('/professionals/{professional}/fresha/disconnect', [StaffFreshaController::class, 'disconnect']);
        });

        // Override brand commission rate and payout hold days (admin only)
        Route::patch('/professionals/{professional}/store-settings', [StaffStoreSettingsController::class, 'update']);

        // #CATALOG-2 — admin catalog overrides. Reuses the same brand-catalog-writes
        // throttle pool as the self-service controller so staff cannot burn the
        // brand's Shopify rate-limit budget. Writes go straight to Shopify via the
        // shared BrandCatalogService and immediately propagate to the brand-side
        // catalog inspector.
        Route::patch('/professionals/{professional}/brand/catalog/{productGid}/commission', [StaffBrandCatalogController::class, 'updateCommission'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');
        Route::patch('/professionals/{professional}/brand/catalog/{productGid}/discount', [StaffBrandCatalogController::class, 'updateDiscount'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');
        Route::patch('/professionals/{professional}/brand/catalog/{productGid}/active', [StaffBrandCatalogController::class, 'toggleActive'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');

        // B6 #STORE-1 — push the staff-edited commission rate to the brand's Shopify metafield (admin only).
        // Self-service PATCH skips Shopify sync deliberately; this is the opt-in to push.
        Route::post('/professionals/{professional}/store-settings/deploy', [StaffStoreSettingsController::class, 'deploy']);

        // B6 #AFF-PHOTO-1 — DMCA / inappropriate content takedown on an affiliate's custom product photo (admin only).
        Route::delete('/professionals/{professional}/affiliate/products/{gid}/photos/{media}',
            [\App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffAffiliatePhotoController::class, 'destroy'])
            ->where('gid', 'gid://shopify/Product/[0-9]+')
            ->whereUuid('media');

        // GDPR-triggered erasure: support invokes the same lifecycle as self-service
        // but skips the email-token step. Reason field is mandatory.
        // withTrashed: an already-soft-deleted account can still be the subject
        // of an Article 17 request and must be reachable via this endpoint.
        Route::post('/professionals/{professional}/deletion/initiate', [StaffAccountDeletionController::class, 'initiate'])
            ->withTrashed();
        Route::post('/professionals/{professional}/deletion/cancel', [StaffAccountDeletionController::class, 'cancel'])
            ->withTrashed();

        // Promote an additional brand-partner connection to primary on behalf of an
        // affiliate (admin only). Mirrors self-service BrandPartnerController::promote.
        // withoutScopedBindings(): {affiliate} and {brand} are independent Professional
        // models — not parent/child.
        Route::middleware('throttle:30,1')->withoutScopedBindings()->group(function (): void {
            Route::post('/professionals/{affiliate}/brand-partners/{brand}/promote',
                [\App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandPartnerController::class, 'promote'])
                ->whereUuid(['affiliate', 'brand']);
        });

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
