<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-person document sharing - the write side of a column that's existed
 * since document_library's own first migration: `view_principals` already
 * documents `user:{id}` as part of its intended shape, and
 * `DocumentAccess::visibleTo()`/`canView()` already match against it. This
 * table is the structured source of truth for "who has this document been
 * shared with, and can they edit it" - `view_principals` stays the
 * materialized, LIKE-queryable projection of it (see
 * DocumentAccess::computePrincipalsForDocument()), same relationship it
 * already has with `visibility`/`department_id`.
 *
 * Shaped like `document_library_stars` (same per-(document, user) pivot,
 * same CASCADE-on-document/NO-ACTION-on-user reasoning) plus one column:
 * `can_edit`, mirroring `task_management_calendar_shares`'s own boolean for
 * the same view-vs-edit distinction in Calendar sharing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_library_shares', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('document_id')->index();
            $table->unsignedBigInteger('viewer_user_id')->index();
            $table->boolean('can_edit')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('document_id')->references('id')->on('document_library')->onDelete('CASCADE')->onUpdate('NO ACTION');
            $table->foreign('viewer_user_id')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('created_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');

            // One grant per (document, viewer) - sharing again just updates
            // can_edit via updateOrInsert, same upsert shape
            // CalendarShareService::share() already uses.
            $table->unique(['document_id', 'viewer_user_id'], 'document_library_shares_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_library_shares');
    }
};
