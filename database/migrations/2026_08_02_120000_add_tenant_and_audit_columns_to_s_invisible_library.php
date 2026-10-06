<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make s_invisible_library tenant-aware.
 *
 * The table shipped as a single global list of frameworks, mental models and
 * matrices with no sub_institute_id, no audit columns and no soft delete. That
 * was survivable while the screen was read-only, but the Libraries & Taxonomy
 * module gives it full CRUD - and without an owner column one tenant editing or
 * deleting an entry would change it for every tenant on the platform.
 *
 * sub_institute_id is nullable on purpose:
 *   NULL  -> curated platform content, visible to everyone, read-only per tenant
 *   <id>  -> that tenant's own entry, editable and deletable by them
 *
 * Existing rows keep NULL, so the shared catalogue is preserved exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('s_invisible_library')) {
            return;
        }

        Schema::table('s_invisible_library', function (Blueprint $table) {
            if (!Schema::hasColumn('s_invisible_library', 'sub_institute_id')) {
                $table->unsignedBigInteger('sub_institute_id')->nullable()->after('difficulty_level');
                $table->index('sub_institute_id', 's_invisible_library_sub_institute_id_index');
            }
            if (!Schema::hasColumn('s_invisible_library', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('sub_institute_id');
            }
            if (!Schema::hasColumn('s_invisible_library', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
            }
            if (!Schema::hasColumn('s_invisible_library', 'deleted_by')) {
                $table->unsignedBigInteger('deleted_by')->nullable()->after('updated_by');
            }
            if (!Schema::hasColumn('s_invisible_library', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('deleted_by');
            }
            if (!Schema::hasColumn('s_invisible_library', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
            if (!Schema::hasColumn('s_invisible_library', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('s_invisible_library')) {
            return;
        }

        Schema::table('s_invisible_library', function (Blueprint $table) {
            foreach (['deleted_at', 'updated_at', 'created_at', 'deleted_by', 'updated_by', 'created_by'] as $column) {
                if (Schema::hasColumn('s_invisible_library', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('s_invisible_library', 'sub_institute_id')) {
                $table->dropIndex('s_invisible_library_sub_institute_id_index');
                $table->dropColumn('sub_institute_id');
            }
        });
    }
};
