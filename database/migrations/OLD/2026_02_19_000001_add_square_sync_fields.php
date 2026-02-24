<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->timestamp('square_catalog_latest_time')->nullable();
            $table->timestamp('square_last_catalog_sync_at')->nullable();
            $table->text('square_last_catalog_sync_error')->nullable();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('square_catalog_object_id')->nullable()->index();
            $table->string('square_variation_id')->nullable()->index();
            $table->unsignedBigInteger('square_catalog_version')->nullable();
            $table->timestamp('square_last_synced_at')->nullable();
            $table->text('square_sync_error')->nullable();
            $table->unique(['professional_id', 'square_variation_id'], 'services_professional_square_variation_uq');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique('services_professional_square_variation_uq');
            $table->dropColumn([
                'square_catalog_object_id',
                'square_variation_id',
                'square_catalog_version',
                'square_last_synced_at',
                'square_sync_error',
            ]);
        });

        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn([
                'square_catalog_latest_time',
                'square_last_catalog_sync_at',
                'square_last_catalog_sync_error',
            ]);
        });
    }
};

