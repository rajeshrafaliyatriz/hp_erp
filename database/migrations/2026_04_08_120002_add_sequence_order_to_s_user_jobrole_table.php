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
        if (!Schema::hasTable('s_user_jobrole') || Schema::hasColumn('s_user_jobrole', 'sequence_order')) {
            return;
        }

        Schema::table('s_user_jobrole', function (Blueprint $table) {
            $table->integer('sequence_order')->after('job_level')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('s_user_jobrole') || !Schema::hasColumn('s_user_jobrole', 'sequence_order')) {
            return;
        }

        Schema::table('s_user_jobrole', function (Blueprint $table) {
            $table->dropColumn('sequence_order');
        });
    }
};
