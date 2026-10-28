<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (report, person it's shared with) - modeled directly on
 * `document_library_shares` / App\Services\Documents\DocumentShareService,
 * the established pattern in this codebase for "share one record with
 * specific named people". "Shared With Me" (App\Domain\Reports\
 * ReportShareService::incoming(), naming borrowed from
 * App\Services\TaskManagement\CalendarShareService) is a live query against
 * this table, not a cached/materialized view.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_shares')) {
            return;
        }

        Schema::create('report_shares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('report_id');
            $table->unsignedBigInteger('viewer_user_id');
            $table->boolean('can_edit')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['report_id', 'viewer_user_id']);
            $table->index('viewer_user_id');

            $table->foreign('report_id')->references('id')->on('reports')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_shares');
    }
};
