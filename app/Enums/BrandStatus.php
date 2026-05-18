<?php

namespace App\Enums;

/**
 * Single source of truth for brand lifecycle stages.
 *
 * Stages 1-5 are progressive — brands step through them in order.
 * disconnected and systems_down are out-of-band states.
 *
 * Values are enforced at the DB level on brand_status_history.from_status/to_status.
 * @see supabase/migrations/202605190000002_add_enum_check_constraints.sql
 */
enum BrandStatus: string
{
    case Onboarding = 'onboarding';
    case ShopifyLinked = 'shopify_linked';
    case ShopifyConfigured = 'shopify_configured';
    case StorefrontLive = 'storefront_live';
    case ReadyForAffiliates = 'ready_for_affiliates';
    case Disconnected = 'disconnected';
    case SystemsDown = 'systems_down';

    /** Stages in progression order. disconnected and systems_down are out-of-band. */
    public static function progression(): array
    {
        return [
            self::Onboarding,
            self::ShopifyLinked,
            self::ShopifyConfigured,
            self::StorefrontLive,
            self::ReadyForAffiliates,
        ];
    }

    public function isAtLeast(self $other): bool
    {
        $progression = self::progression();
        $thisIndex = array_search($this, $progression, true);
        $otherIndex = array_search($other, $progression, true);
        if ($thisIndex === false || $otherIndex === false) {
            return false;
        }

        return $thisIndex >= $otherIndex;
    }

    public function label(): string
    {
        return match ($this) {
            self::Onboarding => 'Onboarding',
            self::ShopifyLinked => 'Shopify Linked',
            self::ShopifyConfigured => 'Shopify Configured',
            self::StorefrontLive => 'Storefront Live',
            self::ReadyForAffiliates => 'Ready for Affiliates',
            self::Disconnected => 'Disconnected',
            self::SystemsDown => 'Systems Down',
        };
    }

    /** 1-based step number for progressive stages. 0 for out-of-band states. */
    public function stepNumber(): int
    {
        $progression = self::progression();
        $index = array_search($this, $progression, true);

        return $index !== false ? $index + 1 : 0;
    }

    /** Map old status strings to new enum cases. */
    public static function fromLegacy(string $legacy): self
    {
        return match ($legacy) {
            'building' => self::Onboarding,
            'preview' => self::StorefrontLive,
            'live' => self::ReadyForAffiliates,
            'systems_down' => self::SystemsDown,
            default => self::Onboarding,
        };
    }
}
