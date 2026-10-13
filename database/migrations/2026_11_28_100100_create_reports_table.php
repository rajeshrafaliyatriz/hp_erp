<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved report. One row per report regardless of type (tabular / summary
 * / pivot / chart) - `definition` (JSON) carries everything type-specific:
 * selected columns, the standard date filter, the advanced AND/OR filter
 * tree, sort/group columns, pivot rows/columns/values, chart type + data
 * fields. See Docs/reports-engine/PLAN.md section 2 for the exact shape.
 *
 * Deliberately NOT normalized into vtiger's own 10+ supporting tables
 * (vtiger_selectcolumn, vtiger_relcriteria, vtiger_relcriteria_grouping,
 * vtiger_reportsortcol, ...) for the same data - nothing here needs to join
 * against an individual column or filter condition, so one JSON column is
 * simpler and correct rather than a normalization exercise with no payoff.
 *
 * `definition` is never trusted as execute-safe on its own: every field
 * reference in it is re-validated against App\Domain\Reports\
 * ReportModuleRegistry on every read AND every write, by the execution
 * service and the controller respectively - this column holds what the
 * user asked for, not something assumed safe to turn into SQL as-is.
 *
 * `definition` is longText, not json(): the `live` connection's MariaDB
 * (10.1.48) predates native JSON support (10.2.7+) and a json() column
 * fails outright there - same fix already applied elsewhere in this
 * codebase ("change json columns to longtext in ai migrations"). The app
 * layer still decodes/encodes it as JSON via Eloquent-style casting or
 * explicit json_decode/json_encode; only the column TYPE differs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reports')) {
            return;
        }

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->string('module_scope', 30); // 'task_management' | 'crm'
            $table->unsignedBigInteger('folder_id')->nullable();
            $table->string('report_type', 20); // tabular | summary | pivot | chart
            $table->string('primary_module', 80); // registry key, e.g. 'crm.leads'
            $table->string('title', 191);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('owner_id');
            $table->string('sharingtype', 10)->default('Private'); // Private | Public
            $table->longText('definition');
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sub_institute_id', 'module_scope', 'folder_id']);
            $table->index('owner_id');
            $table->index('sharingtype');

            $table->foreign('folder_id')->references('id')->on('report_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
