<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE IF EXISTS core.site_images ALTER COLUMN is_active SET DEFAULT true');
        DB::statement('UPDATE core.site_images SET is_active = true WHERE is_active IS NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS core.site_images ALTER COLUMN is_active DROP DEFAULT');
    }
};
