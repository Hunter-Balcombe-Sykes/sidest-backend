<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->text('square_access_token')->nullable();
            $table->text('square_refresh_token')->nullable();
            $table->string('square_merchant_id')->nullable();
            $table->timestamp('square_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn([
                'square_access_token',
                'square_refresh_token',
                'square_merchant_id',
                'square_expires_at',
            ]);
        });
    }
};
