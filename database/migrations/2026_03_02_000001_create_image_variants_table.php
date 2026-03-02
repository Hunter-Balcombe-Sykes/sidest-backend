<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // 1. Add `pool` column to site_images (gallery | content)
        // ---------------------------------------------------------------
        Schema::table('site_images', function (Blueprint $table) {
            $table->string('pool', 20)->default('gallery')->after('site_id');
            $table->index(['site_id', 'pool', 'is_active'], 'si_pool_active');
        });

        // ---------------------------------------------------------------
        // 2. Image variants – one row per processed WebP size
        // ---------------------------------------------------------------
        Schema::create('image_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // FK to site_images – every variant belongs to a single image row
            $table->uuid('image_id');
            $table->foreign('image_id')
                  ->references('id')
                  ->on('site_images')
                  ->cascadeOnDelete();

            $table->string('variant', 20);               // thumb, small, medium, large, hero
            $table->string('disk', 40)->default('media');
            $table->string('path', 500);                  // e.g. images/<proId>/<imageId>/thumb_abc123.webp
            $table->string('format', 10)->default('webp');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('file_size');          // bytes
            $table->string('content_hash', 16);            // first 8 bytes of sha256, hex-encoded

            $table->timestamps();

            // One variant name per image
            $table->unique(['image_id', 'variant'], 'iv_image_variant');
            $table->index('image_id', 'iv_image_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_variants');

        Schema::table('site_images', function (Blueprint $table) {
            $table->dropIndex('si_pool_active');
            $table->dropColumn('pool');
        });
    }
};
