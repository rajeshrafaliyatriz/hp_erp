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
        Schema::create('career_journey', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('jobrole_id');
            $table->unsignedBigInteger('to_jobrole_id');
            $table->boolean('vertical_lateral_movement')->comment('1, 0');
            $table->unsignedBigInteger('sub_institute_id');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('jobrole_id')
                ->references('id')->on('s_user_jobrole')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('to_jobrole_id')
                ->references('id')->on('s_user_jobrole')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('career_journey');
    }
};
