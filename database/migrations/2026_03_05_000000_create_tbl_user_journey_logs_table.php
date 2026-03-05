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
        Schema::create('tbl_user_journey_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('menu_id');
            $table->string('access_link', 255);
            $table->enum('event_type', ['page_visit', 'tour_step_view', 'tour_step_complete', 'tour_skipped']);
            $table->string('step_key', 100)->nullable();
            $table->timestamp('timestamp')->useCurrent();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('tbluser')->onDelete('cascade');
            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('cascade');
            $table->foreign('menu_id')->references('id')->on('tblmenumaster')->onDelete('cascade');

            // Indexes
            $table->index('user_id');
            $table->index('menu_id');
            $table->index('event_type');
            $table->index('timestamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_user_journey_logs');
    }
};
