<?php

namespace App\Services\Cache;

// V2: Central cache key naming convention. All cache keys across the application flow through this class.
//
// ONE-SITE-PER-PROFESSIONAL ASSUMPTION: Many keys below are namespaced by professionalId rather than siteId.
// This is intentional and correct for the current data model (each professional has exactly one site).
// If multi-site support is introduced, any key that caches site-scoped data under professionalId will need
// a siteId segment added — otherwise two sites owned by the same professional would share a cache entry.
// Methods carrying this assumption are annotated with "@multi-site: needs site_id".
class CacheKeyGenerator
{
    public static function publicSite(string $subdomain): string
    {
        return 'site:public:'.strtolower($subdomain);
    }

    public static function publicSitePayload(string $subdomain): string
    {
        return 'site:payload:'.strtolower($subdomain);
    }

    public static function professionalByHandle(string $handle): string
    {
        return 'pro:handle:'.strtolower($handle);
    }

    public static function professionalById(string $id): string
    {
        return "pro:id:{$id}";
    }

    public static function professionalByAuthId(string $authUserId): string
    {
        return "pro:auth:{$authUserId}";
    }

    public static function theme(string $themeId): string
    {
        return "theme:{$themeId}";
    }

    public static function siteBlocks(string $siteId, string $group): string
    {
        return "site:{$siteId}:blocks:{$group}";
    }

    public static function professionalServices(string $professionalId): string
    {
        return "pro:{$professionalId}:services:active";
    }

    public static function siteImages(string $siteId): string
    {
        return "site:{$siteId}:images:active";
    }

    // @multi-site: needs site_id — visits belong to a site, not just a professional
    public static function analyticsVisits(string $professionalId, string $startDate, string $endDate): string
    {
        return "analytics:visits:{$professionalId}:{$startDate}:{$endDate}";
    }

    // @multi-site: needs site_id — clicks belong to a site, not just a professional
    public static function analyticsClicks(string $professionalId, string $startDate, string $endDate): string
    {
        return "analytics:clicks:{$professionalId}:{$startDate}:{$endDate}";
    }

    public static function customerCount(string $professionalId): string
    {
        return "pro:{$professionalId}:customers:count";
    }

    public static function professionalPayloadById(string $id): string
    {
        return "pro:payload:id:{$id}";
    }

    public static function professionalPayloadByHandle(string $handleLc): string
    {
        return 'pro:payload:handle:'.strtolower($handleLc);
    }

    public static function professionalPayloadByAuthId(string $authUserId): string
    {
        return "pro:payload:auth:{$authUserId}";
    }

    public static function professionalIdByHandle(string $handleLc): string
    {
        return 'pro:map:handle:'.strtolower($handleLc);
    }

    public static function professionalIdByAuthId(string $authUserId): string
    {
        return "pro:map:auth:{$authUserId}";
    }

    // @multi-site: needs site_id — summary aggregates site traffic, scoped to one site under current model
    public static function analyticsSummary(string $professionalId, string $startDate, string $endDate): string
    {
        // q3: commerce fields now read from commerce.orders instead of commission_movements (Phase 3)
        return "analytics:summary:q3:{$professionalId}:{$startDate}:{$endDate}";
    }

    /**
     * Version token used to bust all analytics summary keys for a professional at once.
     * Incrementing this key makes every date-range summary key for the professional stale
     * without requiring a full key-space scan.
     *
     * @multi-site: needs site_id — if multi-site, version tokens must be per-site
     */
    public static function analyticsSummaryVersion(string $professionalId): string
    {
        return "analytics:summary:ver:{$professionalId}";
    }

    public static function brandFontActive(string $brandProfessionalId): string
    {
        return "brand:{$brandProfessionalId}:font:active";
    }

    // @multi-site: needs site_id if booking widgets are ever per-site
    public static function bookingAnalytics(string $professionalId, string $from, string $to, string $groupBy): string
    {
        return "analytics:booking:{$professionalId}:{$from}:{$to}:{$groupBy}";
    }

    // @multi-site: needs site_id — commerce traffic is tied to a site storefront
    public static function affiliateCommerceAnalytics(string $professionalId, string $from, string $to): string
    {
        // v2: read path switched to live commerce.orders + brand_affiliate_rollup queries (Phase 3)
        return "analytics:commerce:affiliate:v2:{$professionalId}:{$from}:{$to}";
    }

    // Payout + grace state are current-state snapshots, not window-dependent.
    // Cached per-professional so switching date ranges reuses the same entry.
    public static function affiliatePayoutState(string $professionalId): string
    {
        return "analytics:commerce:affiliate:{$professionalId}:payout-state";
    }

    // @multi-site: needs site_id — commerce traffic is tied to a site storefront
    public static function brandCommerceAnalytics(string $professionalId, string $from, string $to): string
    {
        // v3: read path switched to live commerce.orders + brand_affiliate_rollup queries (Phase 3)
        return "analytics:commerce:brand:v3:{$professionalId}:{$from}:{$to}";
    }

    public static function brandActiveCatalog(string $brandProfessionalId): string
    {
        return "brand:{$brandProfessionalId}:catalog:active";
    }

    public static function brandAdminCatalog(string $brandProfessionalId): string
    {
        return "brand:{$brandProfessionalId}:catalog:admin";
    }

    public static function brandCollectionGid(string $brandProfessionalId, string $handle): string
    {
        return "brand:{$brandProfessionalId}:collection_gid:{$handle}";
    }

    // Hydrogen brand-design response cache. Keyed by site_id (not professional)
    // so BrandDesignMediaService can bust with just the site handle. The `v1`
    // segment lets us bust every entry at once by bumping to v2 if the payload
    // shape changes. TTL is intentionally tight (5s) so Hydrogen sees dashboard
    // saves within its staleWhileRevalidate window — invalidation keeps the
    // stale window near zero in practice.
    public static function hydrogenBrandDesign(string $siteId): string
    {
        return "hydrogen:brand-design:v1:{$siteId}";
    }

    public static function brandProductCustomPhotos(string $brandProfessionalId, string $productGid): string
    {
        return "brand:{$brandProfessionalId}:product:{$productGid}:custom_photos";
    }

    /**
     * Booking-milestone totals snapshot. Caches the (lifetime bookings_count,
     * lifetime total_spent_cents) tuple for the milestone-notification path so
     * a burst of bookings doesn't re-scan analytics.booking_events for each one.
     * Once a pro crosses the highest threshold, the same value is read from cache
     * and the publisher's dedupe key suppresses the redundant notification.
     */
    public static function bookingMilestoneTotals(string $professionalId): string
    {
        return "pro:{$professionalId}:bookings:milestone-totals";
    }
}
