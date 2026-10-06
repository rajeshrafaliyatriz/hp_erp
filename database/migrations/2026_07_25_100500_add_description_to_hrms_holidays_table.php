<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Holiday Calendar screen already captures an optional description;
     * hrms_holidays had nowhere to put it.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('hrms_holidays', 'description')) {
            Schema::table('hrms_holidays', function (Blueprint $table) {
                $table->string('description', 500)->nullable()->after('holiday_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hrms_holidays', 'description')) {
            Schema::table('hrms_holidays', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
