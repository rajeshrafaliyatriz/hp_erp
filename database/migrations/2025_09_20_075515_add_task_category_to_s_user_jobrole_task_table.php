<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('s_user_jobrole_task', function (Blueprint $table) {
            $table->string('task_category', 255)->nullable(); // add the new column
        });
    }

    public function down(): void
    {
        Schema::table('s_user_jobrole_task', function (Blueprint $table) {
            $table->dropColumn('task_category');
        });
    }
};
