<?php

namespace App\Services\Shopify;

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;

// V2: Value object returned by BrandSignupService containing the professional, site, brand profile, integration, and reinstall flag.
class BrandSignupResult
{
    public function __construct(
        public readonly Professional $professional,
        public readonly Site $site,
        public readonly ?BrandProfile $brandProfile,
        public readonly ProfessionalIntegration $integration,
        public readonly bool $isReinstall,
    ) {}
}
