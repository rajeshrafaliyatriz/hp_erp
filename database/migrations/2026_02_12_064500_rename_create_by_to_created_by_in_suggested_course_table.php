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
        Schema::table('suggested_course', function (Blueprint $table) {
            // Drop the foreign key for create_by
            $table->dropForeign(['create_by']);
            
            // Rename the column
            $table->renameColumn('create_by', 'created_by');
            
            // Re-add the foreign key with the new name
            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suggested_course', function (Blueprint $table) {
            // Drop the foreign key for created_by
            $table->dropForeign(['created_by']);
            
            // Rename the column back
            $table->renameColumn('created_by', 'create_by');
            
            // Re-add the foreign key with the original name
            $table->foreign('create_by')
                ->references('id')
                ->on('users')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');
        });
    }
};
