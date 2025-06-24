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
        Schema::create('institute_detail', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();

            $table->string('principal_name', 191)->nullable();
            $table->string('principal_mobile', 15)->nullable();
            $table->string('manager_name', 191)->nullable();
            $table->string('manager_mobile', 15)->nullable();

            $table->string('college_location_condition', 50)->nullable();
            $table->string('total_seats_for_exam', 50)->nullable();
            $table->string('total_furniture', 50)->nullable();
            $table->string('electricity_condition', 50)->nullable();
            $table->string('generator_inverter_condition', 50)->nullable();
            $table->string('drinking_water_condition', 50)->nullable();
            $table->string('toilet_condition', 50)->nullable();
            $table->string('fire_fighting_condition', 50)->nullable();
            $table->string('parking_condition', 50)->nullable();
            $table->string('school_to_road_condition_distance', 50)->nullable();
            $table->string('cctv_condition', 50)->nullable();
            $table->string('total_rooms_with_size', 50)->nullable();
            $table->string('storeroom_condition', 50)->nullable();
            $table->string('college_boundary_gate_condition', 50)->nullable();
            $table->string('principal_house_inside_college', 50)->nullable();
            $table->string('declared_dibar', 50)->nullable();
            $table->string('data_available_AISHE', 50)->nullable();
            $table->string('trustee_conflict', 50)->nullable();
            $table->string('affilitated_college_condition', 50)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Foreign Keys
            $table->foreign('sub_institute_id')
                ->references('id')
                ->on('school_setup')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('created_by')
                ->references('id')
                ->on('tbluser')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('updated_by')
                ->references('id')
                ->on('tbluser')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('deleted_by')
                ->references('id')
                ->on('tbluser')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('institute_detail');
    }
};
