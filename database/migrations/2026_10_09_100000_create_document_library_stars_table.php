<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-viewer bookmarks on document_library rows - a Drive-style "Starred".
 *
 * A pivot table, not a boolean column on document_library: starring is
 * personal state ("did I star this"), not a fact about the document itself
 * - two different people see two different answers for the same row, so it
 * cannot live as one flag on one row the way `visibility` can.
 *
 * `document_id` cascades on delete: nothing in this app hard-deletes
 * document_library rows today (only soft-deletes via deleted_at), so this is
 * dormant insurance against future orphaned star rows - the same reasoning
 * document_library_history already applies to the same parent table.
 *
 * No `updated_at`: a star is toggled by insert/delete, never updated in
 * place - same shape as document_library_history's own audit rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_library_stars', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('document_id')->index();
            $table->unsignedBigInteger('user_id')->index();

            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('document_id')->references('id')->on('document_library')->onDelete('CASCADE')->onUpdate('NO ACTION');
            $table->foreign('user_id')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');

            // One star per (document, viewer) - re-starring is a no-op via
            // insertOrIgnore, not a duplicate row.
            $table->unique(['document_id', 'user_id'], 'document_library_stars_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_library_stars');
    }
};
