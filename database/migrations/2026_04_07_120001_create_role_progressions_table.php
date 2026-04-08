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
        if (Schema::hasTable('role_progressions')) {
            return;
        }

        Schema::create('role_progressions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('from_role_id')->index();
            $table->unsignedBigInteger('to_role_id')->index();
            $table->enum('type', ['vertical', 'lateral']);
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamp('created_at')->nullable();

            $table->foreign('from_role_id')
                ->references('id')->on('s_user_jobrole')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('to_role_id')
                ->references('id')->on('s_user_jobrole')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_progressions');
    }
};
