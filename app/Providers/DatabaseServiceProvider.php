<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Set PostgreSQL timeouts on each connection
        DB::listen(function ($query) {
            if (DB::getDriverName() === 'pgsql') {
                $statementTimeout = config('database.connections.pgsql.statement_timeout', 30000);
                $lockTimeout = config('database.connections.pgsql.lock_timeout', 10000);

                DB::statement("SET statement_timeout = {$statementTimeout}");
                DB::statement("SET lock_timeout = {$lockTimeout}");
            }
        });
    }
}
