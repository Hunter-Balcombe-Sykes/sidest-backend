<?php

namespace App\Providers;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Observers\Core\BlockObserver;
use App\Observers\Professional\ProfessionalObserver;
use App\Observers\Core\ServiceObserver;
use App\Observers\Core\SiteObserver;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        // existing events...
    ];

    public function boot(): void
    {
        Professional::observe(ProfessionalObserver::class);
        Site::observe(SiteObserver::class);
        Block::observe(BlockObserver:: class);
        Service::observe(ServiceObserver::class);
    }
}
