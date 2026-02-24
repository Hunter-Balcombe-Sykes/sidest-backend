<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('fresha_service_id')->nullable()->index();
            $table->string('fresha_variation_id')->nullable()->index();
            $table->unsignedBigInteger('fresha_service_version')->nullable();
            $table->timestamp('fresha_last_synced_at')->nullable();
            $table->text('fresha_sync_error')->nullable();
            $table->unique(['professional_id', 'fresha_variation_id'], 'services_professional_fresha_variation_uq');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique('services_professional_fresha_variation_uq');
            $table->dropColumn([
                'fresha_service_id',
                'fresha_variation_id',
                'fresha_service_version',
                'fresha_last_synced_at',
                'fresha_sync_error',
            ]);
        });
    }
};