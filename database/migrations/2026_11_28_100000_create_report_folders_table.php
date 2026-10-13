<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report folders — the left-rail categories a report lives under.
 *
 * "All Reports" and "Shared With Me" are deliberately NOT rows here. The
 * real vtiger Reports module (the reference this engine is modeled on,
 * Docs/reports-engine/PLAN.md) reserves exactly those two names as
 * unsavable folder names (`Folder.php::checkDuplicate()`) because both are
 * computed views over the `reports` table, not a fixed set of contents -
 * "All Reports" is every report the viewer may see across every folder,
 * "Shared With Me" is every private report explicitly shared with the
 * viewer. Giving either a real folder row would mean keeping it in sync
 * with every report create/delete/share, for no benefit.
 *
 * `is_system` distinguishes the folders this engine seeds per module
 * (Task Reports, Lead Reports, ...) from folders a user creates themselves
 * (the "Triz Reports" ask - an ordinary custom folder, confirmed NOT a
 * stock vtiger concept, see the plan doc). System folders have no owner;
 * custom folders are owned by whoever created them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_folders')) {
            return;
        }

        Schema::create('report_folders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->string('module_scope', 30); // 'task_management' | 'crm'
            $table->string('name', 191);
            $table->boolean('is_system')->default(false);
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sub_institute_id', 'module_scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_folders');
    }
};
