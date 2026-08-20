<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A PROJECT CAN NOW SPAN SEVERAL DEPARTMENTS.
 *
 * `task_management_projects.department_id` holds exactly one, and nothing in
 * the schema could hold a list. Two shapes were already present as precedent:
 * `regulatory_flags` stores JSON in a TEXT column, and
 * `task_management_project_members` is a proper join table.
 *
 * THE JOIN TABLE WAS CHOSEN. A JSON list cannot be joined or filtered in SQL,
 * so "show every project for Finance" — the obvious next request — would mean
 * loading every project and filtering in PHP. This mirrors project_members,
 * including its cascade, so it behaves the way the rest of the module does.
 *
 * `projects.department_id` IS KEPT, and keeps meaning THE PRIMARY department.
 * Existing joins (the project list, the Home dashboard, every report) go on
 * working untouched, and the controller writes it from whichever row carries
 * is_primary. One fact, one authority, no silent divergence.
 *
 * NO sub_institute_id, DELIBERATELY — the same as project_members and
 * project_tasks. Tenancy is inherited through project_id, and every query
 * against this table must join task_management_projects and filter
 * sub_institute_id + syear. Adding a tenant column here would create a second
 * place for it to be wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_management_project_departments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('department_id')->index();
            // Exactly one row per project should carry this; the controller
            // enforces it on write, since MySQL cannot express "unique where
            // true" without a generated column.
            $table->boolean('is_primary')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('task_management_projects')->cascadeOnDelete();
            $table->unique(['project_id', 'department_id'], 'tm_project_department_unique');
        });

        // BACKFILL, so nothing regresses on day one. Every project that already
        // names a department gets that department as its primary row — the new
        // table starts out agreeing with the old column rather than empty,
        // which would have made every existing project look department-less.
        $existing = DB::table('task_management_projects')
            ->whereNotNull('department_id')
            ->where('department_id', '>', 0)
            ->get(['id', 'department_id', 'created_by']);

        foreach ($existing->chunk(200) as $chunk) {
            DB::table('task_management_project_departments')->insert(
                $chunk->map(fn ($row) => [
                    'project_id'    => $row->id,
                    'department_id' => $row->department_id,
                    'is_primary'    => true,
                    'created_by'    => $row->created_by,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ])->all()
            );
        }
    }

    public function down(): void
    {
        // Safe to drop: projects.department_id still holds the primary, so no
        // information is lost that was not already there before this migration.
        Schema::dropIfExists('task_management_project_departments');
    }
};
