<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // We use CREATE INDEX CONCURRENTLY which cannot run in a transaction.
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("
            ALTER TABLE core.sites
            ADD COLUMN IF NOT EXISTS subdomain_changed_at timestamptz
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS core.site_subdomain_aliases (
                id uuid PRIMARY KEY,
                site_id uuid NOT NULL REFERENCES core.sites(id) ON DELETE CASCADE,
                subdomain varchar(63) NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )
        ");

        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS core_site_subdomain_aliases_site_id_idx
            ON core.site_subdomain_aliases (site_id)
        ");

        DB::statement("
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS core_site_subdomain_aliases_subdomain_lower_unique
            ON core.site_subdomain_aliases ((lower(subdomain)))
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS core_site_subdomain_aliases_subdomain_lower_unique");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS core_site_subdomain_aliases_site_id_idx");
        DB::statement("DROP TABLE IF EXISTS core.site_subdomain_aliases");
        DB::statement("ALTER TABLE core.sites DROP COLUMN IF EXISTS subdomain_changed_at");
    }
};
