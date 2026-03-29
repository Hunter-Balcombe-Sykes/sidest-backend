<?php

namespace App\Providers;

use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Professional\ProfessionalLegalContent;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Retail\CommissionLedgerEntry;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\ProfessionalSelection;
use App\Models\Core\Professional\Customer;
use App\Observers\Core\BrandAffiliateInviteObserver;
use App\Observers\Core\BrandProfileObserver;
use App\Observers\Core\BlockObserver;
use App\Observers\Core\CommissionLedgerEntryObserver;
use App\Observers\Core\CommissionPayoutObserver;
use App\Observers\Core\CustomerObserver;
use App\Observers\Core\ProfessionalIntegrationObserver;
use App\Observers\Core\ProfessionalLegalContentObserver;
use App\Observers\Professional\ProfessionalObserver;
use App\Observers\Core\ServiceObserver;
use App\Observers\Core\SiteObserver;
use App\Observers\Retail\ProfessionalSelectionObserver;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        // existing events...
    ];

    public function boot(): void
    {
        Professional::observe(ProfessionalObserver::class);
        ProfessionalLegalContent::observe(ProfessionalLegalContentObserver::class);
        Site::observe(SiteObserver::class);
        Block::observe(BlockObserver::class);
        Service::observe(ServiceObserver::class);
        Customer::observe(CustomerObserver::class);
        BrandAffiliateInvite::observe(BrandAffiliateInviteObserver::class);
        CommissionLedgerEntry::observe(CommissionLedgerEntryObserver::class);
        CommissionPayout::observe(CommissionPayoutObserver::class);
        ProfessionalIntegration::observe(ProfessionalIntegrationObserver::class);
        BrandProfile::observe(BrandProfileObserver::class);
        ProfessionalSelection::observe(ProfessionalSelectionObserver::class);
    }
}
