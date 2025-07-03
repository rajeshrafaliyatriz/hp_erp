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
        Schema::table('s_skill_matrix', function (Blueprint $table) {
            //
            $table->text('knowledge')->after('interest_level')->nullable();
            $table->text('ability')->after('knowledge')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('s_skill_matrix', function (Blueprint $table) {
            //
             $table->dropColumn([
                'knowledge',
                'ability',
            ]);
        });
    }
};
