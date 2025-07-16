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
        Schema::table('institute_detail', function (Blueprint $table) {
              // Drop existing columns
            $table->dropColumn([
                'principal_name',
                'principal_mobile',
                'manager_name',
                'manager_mobile',
                'college_location_condition',
                'total_seats_for_exam',
                'total_furniture',
                'electricity_condition',
                'generator_inverter_condition',
                'drinking_water_condition',
                'toilet_condition',
                'fire_fighting_condition',
                'parking_condition',
                'school_to_road_condition_distance',
                'cctv_condition',
                'total_rooms_with_size'
            ]);
            
            // Add new columns
            $table->string('organization_name', 191)->after('sub_institute_id')->nullable();
            $table->string('organization_code', 191)->after('organization_name')->nullable();
            $table->string('organization_type', 191)->after('organization_code')->nullable();
            $table->string('organization_email', 191)->after('organization_type')->nullable();
            $table->string('organization_ph_no', 191)->after('organization_email')->nullable();
            $table->string('organization_website', 191)->after('organization_ph_no')->nullable();
            $table->string('address', 191)->after('organization_website')->nullable();
            $table->string('industry_type', 191)->after('address')->nullable();
            $table->string('registration_number', 191)->after('industry_type')->nullable();
            $table->string('handler_name', 191)->after('registration_number')->nullable();
            $table->string('handler_mobile', 191)->after('handler_name')->nullable();
            $table->string('handler_email', 191)->after('handler_mobile')->nullable();
            $table->string('total_emp', 191)->after('handler_email')->nullable();
            $table->string('total_department', 191)->after('total_emp')->nullable();
            $table->string('working_days', 191)->after('total_department')->nullable();
            $table->string('working_hours', 191)->after('working_days')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::table('institute_detail', function (Blueprint $table) {
            // Re-add dropped columns
            $table->string('principal_name', 191)->nullable();
            $table->string('principal_mobile', 191)->nullable();
            $table->string('manager_name', 191)->nullable();
            $table->string('manager_mobile', 191)->nullable();
            $table->string('college_location_condition', 191)->nullable();
            $table->string('total_seats_for_exam', 191)->nullable();
            $table->string('total_furniture', 191)->nullable();
            $table->string('electricity_condition', 191)->nullable();
            $table->string('generator_inverter_condition', 191)->nullable();
            $table->string('drinking_water_condition', 191)->nullable();
            $table->string('toilet_condition', 191)->nullable();
            $table->string('fire_fighting_condition', 191)->nullable();
            $table->string('parking_condition', 191)->nullable();
            $table->string('school_to_road_condition_distance', 191)->nullable();
            $table->string('cctv_condition', 191)->nullable();
            $table->string('total_rooms_with_size', 191)->nullable();
            
            // Drop new columns
            $table->dropColumn([
                'organization_name',
                'organization_code',
                'organization_type',
                'organization_email',
                'organization_ph_no',
                'organization_website',
                'address',
                'industry_type',
                'registration_number',
                'handler_name',
                'handler_mobile',
                'handler_email',
                'total_emp',
                'total_department',
                'working_days',
                'working_hours'
            ]);
        });
    }
};
