<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->text('fresha_access_token')->nullable();
            $table->text('fresha_refresh_token')->nullable();
            $table->string('fresha_business_id')->nullable();
            $table->timestamp('fresha_expires_at')->nullable();
            $table->timestamp('fresha_catalog_latest_time')->nullable();
            $table->timestamp('fresha_last_catalog_sync_at')->nullable();
            $table->text('fresha_last_catalog_sync_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn([
                'fresha_access_token',
                'fresha_refresh_token',
                'fresha_business_id',
                'fresha_expires_at',
                'fresha_catalog_latest_time',
                'fresha_last_catalog_sync_at',
                'fresha_last_catalog_sync_error',
            ]);
        });
    }
};