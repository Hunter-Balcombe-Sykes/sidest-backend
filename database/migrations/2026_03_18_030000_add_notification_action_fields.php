<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE core.notifications ADD COLUMN IF NOT EXISTS primary_action_label varchar(255) NULL");
        DB::statement("ALTER TABLE core.notifications ADD COLUMN IF NOT EXISTS secondary_action_label varchar(255) NULL");
        DB::statement("ALTER TABLE core.notifications ADD COLUMN IF NOT EXISTS secondary_action_url text NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE core.notifications DROP COLUMN IF EXISTS secondary_action_url");
        DB::statement("ALTER TABLE core.notifications DROP COLUMN IF EXISTS secondary_action_label");
        DB::statement("ALTER TABLE core.notifications DROP COLUMN IF EXISTS primary_action_label");
    }
};
