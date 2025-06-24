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
        Schema::table('s_user_jobrole', function (Blueprint $table) {
            //
            $table->string('industries', 191)->after('id')->nullable()->index();
            $table->string('department', 191)->after('industries')->nullable()->index();
            $table->string('sub_department', 191)->after('department')->nullable()->index();
            $table->text('performance_expectation')->after('description')->nullable();
            $table->enum('status', ['Active', 'Inactive'])->after('performance_expectation')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('s_user_jobrole', function (Blueprint $table) {
            //
             $table->dropColumn([
                'industries',
                'department',
                'sub_department',
                'performance_expectation',
                'status'
            ]);
        });
    }
};
