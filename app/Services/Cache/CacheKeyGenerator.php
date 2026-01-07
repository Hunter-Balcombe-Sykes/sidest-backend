<?php

namespace App\Services\Cache;

class CacheKeyGenerator
{
    public static function publicSite(string $subdomain): string
    {
        return "site:public:" . strtolower($subdomain);
    }

    public static function publicSitePayload(string $subdomain): string
    {
        return "site:payload:" . strtolower($subdomain);
    }

    public static function professionalByHandle(string $handle): string
    {
        return "pro:handle:" . strtolower($handle);
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
        return "pro:payload:handle:" . strtolower($handleLc);
    }

    public static function professionalPayloadByAuthId(string $authUserId): string
    {
        return "pro:payload:auth:{$authUserId}";
    }

    public static function professionalIdByHandle(string $handleLc): string
    {
        return "pro:map:handle:" . strtolower($handleLc);
    }

    public static function professionalIdByAuthId(string $authUserId): string
    {
        return "pro:map:auth:{$authUserId}";
    }

}
