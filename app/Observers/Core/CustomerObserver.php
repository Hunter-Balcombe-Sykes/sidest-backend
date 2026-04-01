<?php

namespace App\Observers\Core;

use App\Models\Core\Professional\Customer;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;

class CustomerObserver
{
    public bool $afterCommit = true;

    public function created(Customer $customer): void
    {
        $this->invalidateCount($customer);
    }

    public function updated(Customer $customer): void
    {
        $this->invalidateCount($customer);
    }

    public function deleted(Customer $customer): void
    {
        $this->invalidateCount($customer);
    }

    public function restored(Customer $customer): void
    {
        $this->invalidateCount($customer);
    }

    private function invalidateCount(Customer $customer): void
    {
        if (! empty($customer->professional_id)) {
            Cache::forget(CacheKeyGenerator::customerCount((string) $customer->professional_id));
        }
    }
}
