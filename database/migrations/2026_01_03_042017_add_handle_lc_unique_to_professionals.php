<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("ALTER TABLE core.professionals ADD COLUMN IF NOT EXISTS handle_lc text");
        DB::statement("UPDATE core.professionals SET handle_lc = lower(handle) WHERE handle_lc IS NULL");
        DB::statement("ALTER TABLE core.professionals ALTER COLUMN handle_lc SET NOT NULL");

        DB::statement("
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS core_professionals_handle_lc_unique
            ON core.professionals (handle_lc)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS core_professionals_handle_lc_unique");
        DB::statement("ALTER TABLE core.professionals DROP COLUMN IF EXISTS handle_lc");
    }
};
