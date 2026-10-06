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
        if (!Schema::hasTable('tblgroupwise_rights')) {
            return;
        }

        if (!Schema::hasColumn('tblgroupwise_rights', 'is_mobile')) {
            Schema::table('tblgroupwise_rights', function (Blueprint $table) {
                $table->boolean('is_mobile')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('tblgroupwise_rights')) {
            return;
        }

        if (Schema::hasColumn('tblgroupwise_rights', 'is_mobile')) {
            Schema::table('tblgroupwise_rights', function (Blueprint $table) {
                $table->dropColumn('is_mobile');
            });
        }
    }
};