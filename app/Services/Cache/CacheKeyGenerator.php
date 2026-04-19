<?php

namespace App\Services\Cache;

// V2: Central cache key naming convention. All cache keys across the application flow through this class.
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

    public static function analyticsVisits(string $professionalId, string $startDate, string $endDate): string
    {
        return "analytics:visits:{$professionalId}:{$startDate}:{$endDate}";
    }

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

    public static function analyticsSummary(string $professionalId, string $startDate, string $endDate): string
    {
        return "analytics:summary:{$professionalId}:{$startDate}:{$endDate}";
    }

    /**
     * Version token used to bust all analytics summary keys for a professional at once.
     * Incrementing this key makes every date-range summary key for the professional stale
     * without requiring a full key-space scan.
     */
    public static function analyticsSummaryVersion(string $professionalId): string
    {
        return "analytics:summary:ver:{$professionalId}";
    }

    public static function brandFontActive(string $brandProfessionalId): string
    {
        return "brand:{$brandProfessionalId}:font:active";
    }

    public static function bookingAnalytics(string $professionalId, string $from, string $to, string $groupBy): string
    {
        return "analytics:booking:{$professionalId}:{$from}:{$to}:{$groupBy}";
    }

    public static function affiliateCommerceAnalytics(string $professionalId, string $from, string $to): string
    {
        return "analytics:commerce:affiliate:{$professionalId}:{$from}:{$to}";
    }

    public static function brandCommerceAnalytics(string $professionalId, string $from, string $to): string
    {
        return "analytics:commerce:brand:{$professionalId}:{$from}:{$to}";
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
}
