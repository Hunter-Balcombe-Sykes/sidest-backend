<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Because we use CREATE INDEX CONCURRENTLY
    public $withinTransaction = false;

    public function up(): void
    {
        // 1) Add column (nullable first so we can backfill)
        DB::statement("
            ALTER TABLE core.email_subscriptions
            ADD COLUMN IF NOT EXISTS email_lc text
        ");

        // 2) Backfill existing rows
        DB::statement("
            UPDATE core.email_subscriptions
            SET email_lc = lower(email)
            WHERE email_lc IS NULL
        ");

        // 3) Make not null after backfill
        DB::statement("
            ALTER TABLE core.email_subscriptions
            ALTER COLUMN email_lc SET NOT NULL
        ");

        // 4) Add unique index that upsert can target (professional list)
        DB::statement("
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS
            email_subscriptions_unique_pro_list_email_lc
            ON core.email_subscriptions (professional_id, list_key, email_lc)
            WHERE professional_id IS NOT NULL
        ");

        // (Optional but recommended) Add unique index for global lists too
        DB::statement("
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS
            email_subscriptions_unique_global_list_email_lc
            ON core.email_subscriptions (list_key, email_lc)
            WHERE professional_id IS NULL
        ");

        // (Optional cleanup) If you previously created expression indexes like lower(email),
        // you can drop them once you're sure everything is using email_lc:
        //
        // DB::statement("DROP INDEX CONCURRENTLY IF EXISTS email_subscriptions_unique_per_pro_list");
        // DB::statement("DROP INDEX CONCURRENTLY IF EXISTS email_subscriptions_unique_global_list");
    }

    public function down(): void
    {
        // Recreate old indexes here if you dropped them, otherwise just remove the new ones

        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS email_subscriptions_unique_global_list_email_lc");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS email_subscriptions_unique_pro_list_email_lc");

        DB::statement("
            ALTER TABLE core.email_subscriptions
            DROP COLUMN IF EXISTS email_lc
        ");
    }
};
