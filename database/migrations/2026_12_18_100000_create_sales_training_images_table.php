<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pictures used by the G2G sales presentation (module and role diagrams, scene illustrations).
 * They live in the database instead of in the frontend's /public folder, and are served by
 * GET /api/sales-training/images/{slug}. Loaded by `php artisan sales-training:import-images`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_training_images')) {
            return;
        }

        Schema::create('sales_training_images', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();        // e.g. mod-attendance, role-hr
            $table->string('mime', 60);                   // image/svg+xml, image/png, image/jpeg
            $table->string('alt', 255)->nullable();
            $table->string('tag', 120)->nullable();       // label shown under the picture
            $table->string('credit', 255)->nullable();    // attribution, where a licence needs one
            $table->unsignedInteger('bytes');
            $table->char('sha1', 40);
            $table->binary('data');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sales_training_images MODIFY data LONGBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_training_images');
    }
};
