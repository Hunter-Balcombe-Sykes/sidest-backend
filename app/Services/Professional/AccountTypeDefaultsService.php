<?php

namespace App\Services\Professional;

use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Retail\BrandStoreSettings;
use App\Models\Retail\ProfessionalSelection;

class AccountTypeDefaultsService
{
    /**
     * Resolve the effective config for a professional type, handling inheritance.
     */
    public function resolveDefaults(string $professionalType): array
    {
        $all = config('comet.account_type_defaults', []);
        $typeConfig = $all[$professionalType] ?? $all['professional'] ?? [];

        if (isset($typeConfig['inherits'])) {
            $parent = $all[$typeConfig['inherits']] ?? [];
            $typeConfig = array_replace_recursive($parent, $typeConfig);
            unset($typeConfig['inherits']);
        }

        return $typeConfig;
    }

    /**
     * Apply defaults to a newly created professional's site and blocks.
     */
    public function applyDefaults(Professional $professional, Site $site): void
    {
        $defaults = $this->resolveDefaults($professional->professional_type);

        // 1. Set is_published
        if (isset($defaults['is_published'])) {
            $site->is_published = (bool) $defaults['is_published'];
        }

        // 2. Apply default site settings for brands
        if (isset($defaults['default_site_settings'])) {
            $existing = is_array($site->settings) ? $site->settings : [];
            $site->settings = array_replace_recursive($existing, $defaults['default_site_settings']);
        }

        $site->save();

        // 3. Create default section blocks (enabled but not visible)
        $defaultSections = $defaults['default_sections'] ?? [];
        foreach ($defaultSections as $sortOrder => $blockType) {
            Block::query()->firstOrCreate(
                [
                    'professional_id' => $professional->id,
                    'site_id'         => $site->id,
                    'block_group'     => 'sections',
                    'block_type'      => $blockType,
                ],
                [
                    'sort_order'  => $sortOrder,
                    'is_enabled'  => true,
                    'is_active'   => false,
                    'settings'    => [],
                ]
            );
        }

        // 4. Professional type gets sitepage_analytics enabled
        if ($professional->isProfessional()) {
            Block::query()->firstOrCreate(
                [
                    'professional_id' => $professional->id,
                    'site_id'         => $site->id,
                    'block_group'     => 'sections',
                    'block_type'      => 'sitepage_analytics',
                ],
                [
                    'sort_order'  => count($defaultSections),
                    'is_enabled'  => true,
                    'is_active'   => false,
                    'settings'    => [],
                ]
            );
        }
    }

    /**
     * Apply affiliate-specific overlay when connecting to a brand.
     */
    public function applyAffiliateDefaults(
        Professional $professional,
        Site $site,
        string $brandProfessionalId
    ): void {
        $config = config('comet.account_type_defaults.affiliate', []);

        // 1. Auto-enable shop section
        $autoSections = $config['auto_enable_sections'] ?? [];
        foreach ($autoSections as $blockType) {
            $block = Block::query()->firstOrNew([
                'professional_id' => $professional->id,
                'site_id'         => $site->id,
                'block_group'     => 'sections',
                'block_type'      => $blockType,
            ]);

            $block->is_enabled = true;
            $block->is_active  = true;

            if (! $block->exists) {
                $maxSort = Block::query()
                    ->where('site_id', $site->id)
                    ->where('block_group', 'sections')
                    ->max('sort_order');

                $block->sort_order = is_null($maxSort) ? 0 : ((int) $maxSort + 1);
                $block->settings   = [];
            }

            $block->save();
        }

        // 2. Set theme from brand's affiliate default
        if ($config['use_brand_affiliate_theme'] ?? false) {
            $brandSettings = BrandStoreSettings::where('professional_id', $brandProfessionalId)->first();
            if ($brandSettings && $brandSettings->default_affiliate_theme_id) {
                $site->theme_id = $brandSettings->default_affiliate_theme_id;
                $site->save();
            }
        }

        // 3. Create default contact
        if (isset($config['default_contact'])) {
            $this->createDefaultContact($professional, $config['default_contact']);
        }

        // 4. Sync product defaults from brand
        if ($config['use_brand_affiliate_products'] ?? false) {
            $this->syncBrandAffiliateProducts($professional, $brandProfessionalId);
        }
    }

    private function createDefaultContact(Professional $professional, array $contactData): void
    {
        $email = $contactData['email'] ?? null;
        if (! $email) {
            return;
        }

        $customer = Customer::query()->firstOrCreate(
            [
                'professional_id' => $professional->id,
                'email'           => $email,
            ],
            [
                'full_name' => $contactData['full_name'] ?? null,
                'phone'     => $contactData['phone'] ?? null,
                'source'    => $contactData['source'] ?? 'system_default',
            ]
        );

        // Create marketing subscription if configured
        if ($contactData['subscribed'] ?? false) {
            $sub = EmailSubscription::query()->firstOrNew([
                'professional_id' => $professional->id,
                'list_key'        => 'marketing',
                'email_lc'        => strtolower($email),
            ]);

            if (! $sub->exists) {
                $sub->email            = $email;
                $sub->full_name        = $contactData['full_name'] ?? null;
                $sub->unsubscribe_token = EmailSubscription::newUnsubscribeToken();
                $sub->markSubscribed(['source' => 'affiliate_default']);
                $sub->save();
            }
        }
    }

    private function syncBrandAffiliateProducts(Professional $professional, string $brandProfessionalId): void
    {
        $brandSettings = BrandStoreSettings::where('professional_id', $brandProfessionalId)->first();
        if (! $brandSettings) {
            return;
        }

        $productIds = $brandSettings->default_affiliate_product_ids;
        if (empty($productIds)) {
            return;
        }

        foreach ($productIds as $sortOrder => $productId) {
            ProfessionalSelection::query()->firstOrCreate(
                [
                    'professional_id'      => $professional->id,
                    'brand_professional_id' => $brandProfessionalId,
                    'brand_product_id'      => $productId,
                ],
                [
                    'sort_order' => $sortOrder,
                ]
            );
        }
    }
}
