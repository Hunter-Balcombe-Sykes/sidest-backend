<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

// V2: Sets PostgreSQL statement and lock timeouts on the default connection at boot.
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
        // Set PostgreSQL timeouts ONCE per connection, not per query
        $connectionName = config('database.default');

        if ($connectionName !== 'pgsql') {
            return;
        }

        try {
            $pdo = DB::connection()->getPdo();

            $statementTimeout = config('database.connections.pgsql.statement_timeout', 30000);
            $lockTimeout = config('database.connections.pgsql.lock_timeout', 10000);

            $pdo->exec("SET statement_timeout = {$statementTimeout}");
            $pdo->exec("SET lock_timeout = {$lockTimeout}");
        } catch (\PDOException) {
            // Connection unavailable (e.g. missing credentials during config:clear)
        }
    }
}
