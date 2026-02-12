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
            // Add sub_institute_id column with foreign key reference to school_setup table
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->foreign('sub_institute_id')
                ->references('id')
                ->on('school_setup')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            // Add deleted_at column for soft deletes
            $table->timestamp('deleted_at')->nullable();

            // Add create_by, updated_by, deleted_by columns as foreign keys referencing users table
            $table->unsignedBigInteger('create_by')->nullable();
            $table->foreign('create_by')
                ->references('id')
                ->on('users')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('updated_by')
                ->references('id')
                ->on('users')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->foreign('deleted_by')
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
            // Drop foreign keys and columns in reverse order
            $table->dropForeign(['deleted_by']);
            $table->dropColumn('deleted_by');

            $table->dropForeign(['updated_by']);
            $table->dropColumn('updated_by');

            $table->dropForeign(['create_by']);
            $table->dropColumn('create_by');

            $table->dropColumn('deleted_at');

            $table->dropForeign(['sub_institute_id']);
            $table->dropColumn('sub_institute_id');
        });
    }
};
