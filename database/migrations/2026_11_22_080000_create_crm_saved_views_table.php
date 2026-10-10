<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A real, working version of the abandoned `crm_lists` table's idea
 * (left untouched/unused per the plan) - a named shortcut for a list
 * view's current search/filter/sort state. `conditions` is a JSON-encoded
 * object of that module's own query params (search, status filter, sortBy,
 * sortDir) - no soft-deletes, no per-module typed columns: deleting a saved
 * shortcut is not data loss worth a Recycle Bin entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_saved_views', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('module', 20); // leads|contacts|organizations|campaigns
            $table->string('name', 191);
            $table->text('conditions');
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['sub_institute_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_saved_views');
    }
};
