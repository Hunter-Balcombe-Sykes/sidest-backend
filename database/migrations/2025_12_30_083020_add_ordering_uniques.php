<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Services: unique ordering per professional (ignore soft-deleted)
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS services_professional_sort_order_uq
            ON core.services (professional_id, sort_order)
            WHERE deleted_at IS NULL
        ");

        // Link blocks: unique ordering per site within links group
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS blocks_links_site_group_sort_uq
            ON core.blocks (site_id, block_group, sort_order)
            WHERE block_group = 'links'
        ");

        // Section blocks: unique ordering per site within sections group
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS blocks_sections_site_group_sort_uq
            ON core.blocks (site_id, block_group, sort_order)
            WHERE block_group = 'sections'
        ");

        // Section blocks: only one per type per site (prevents duplicate 'bio' blocks etc.)
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS blocks_sections_site_group_type_uq
            ON core.blocks (site_id, block_group, block_type)
            WHERE block_group = 'sections'
        ");

        // Gallery images: unique ordering per site for active, non-deleted images
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS site_images_site_sort_order_active_uq
            ON core.site_images (site_id, sort_order)
            WHERE deleted_at IS NULL AND is_active = true
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS core.services_professional_sort_order_uq");
        DB::statement("DROP INDEX IF EXISTS core.blocks_links_site_group_sort_uq");
        DB::statement("DROP INDEX IF EXISTS core.blocks_sections_site_group_sort_uq");
        DB::statement("DROP INDEX IF EXISTS core.blocks_sections_site_group_type_uq");
        DB::statement("DROP INDEX IF EXISTS core.site_images_site_sort_order_active_uq");
    }
};
