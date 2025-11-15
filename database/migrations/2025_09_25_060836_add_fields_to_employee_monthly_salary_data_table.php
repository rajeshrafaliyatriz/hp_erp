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
       Schema::table('employee_monthly_salary_data', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_monthly_salary_data', 'month')) {
                $table->string('month', 20)->nullable()->after('id');
            }
            if (!Schema::hasColumn('employee_monthly_salary_data', 'year')) {
                $table->integer('year')->nullable()->after('month');
            }
            if (!Schema::hasColumn('employee_monthly_salary_data', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable()->after('year');
            }
            if (!Schema::hasColumn('employee_monthly_salary_data', 'sub_institute_id')) {
                $table->unsignedBigInteger('sub_institute_id')->nullable()->after('employee_id');
            }
            if (!Schema::hasColumn('employee_monthly_salary_data', 'total_deduction')) {
                $table->decimal('total_deduction', 10, 2)->default(0)->after('sub_institute_id');
            }
            if (!Schema::hasColumn('employee_monthly_salary_data', 'total_payment')) {
                $table->decimal('total_payment', 10, 2)->default(0)->after('total_deduction');
            }
            if (!Schema::hasColumn('employee_monthly_salary_data', 'received_by')) {
                $table->string('received_by', 100)->nullable()->after('total_payment');
            }
            if (!Schema::hasColumn('employee_monthly_salary_data', 'total_day')) {
                $table->decimal('total_day', 8, 2)->default(0)->after('received_by');
            }
            if (!Schema::hasColumn('employee_monthly_salary_data', 'employee_salary_data')) {
                $table->longText('employee_salary_data')->nullable()->after('total_day');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_monthly_salary_data', function (Blueprint $table) {
            $table->dropColumn([
                'month',
                'year',
                'employee_id',
                'sub_institute_id',
                'total_deduction',
                'total_payment',
                'received_by',
                'total_day',
                'employee_salary_data',
            ]);
        });
    }
};
