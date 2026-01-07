<?php

namespace App\Providers;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Observers\BlockObserver;
use App\Observers\ProfessionalObserver;
use App\Observers\ServiceObserver;
use App\Observers\SiteObserver;
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
