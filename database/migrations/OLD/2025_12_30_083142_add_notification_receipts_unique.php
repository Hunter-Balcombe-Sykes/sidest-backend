<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS notification_receipts_notification_professional_uq
            ON core.notification_receipts (notification_id, professional_id)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS core.notification_receipts_notification_professional_uq");
    }
};
