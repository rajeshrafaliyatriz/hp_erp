<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('Onboarding_tour_details', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('menu_id')->nullable();
            $table->string('access_link', 255)->nullable();
            $table->enum('event_type', ['page_visit', 'tour_step_view', 'tour_step_complete', 'tour_skipped']);
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('on_click', 255)->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Foreign key
            $table->foreign('menu_id')->references('id')->on('tblmenumaster')->onDelete('cascade');

            // Indexes
            $table->index('menu_id');
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Onboarding_tour_details');
    }
};
