<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-person FOLDER sharing - `document_library_shares`'s twin, one level up
 * the tree. Folders have never had a `view_principals`-equivalent column of
 * their own (`DocumentAccess::canViewFolder()`'s own docblock: "no per-
 * principal sharing in v1") - sharing a folder works instead by cascading
 * INTO every document in its subtree, recomputing each one's existing
 * `document_library.view_principals` to additionally include this folder's
 * (and every ancestor folder's) active shares. See
 * `DocumentAccess::effectiveViewPrincipalsForDocument()` and
 * `DocumentFolderShareService::syncFolderPrincipals()` for the write path -
 * this table is only the structured source of truth for "who has this
 * folder been shared with," not something `visibleFoldersTo()`/
 * `canViewFolder()` query directly.
 *
 * Shaped identically to `document_library_shares`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folder_shares', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('folder_id')->index();
            $table->unsignedBigInteger('viewer_user_id')->index();
            $table->boolean('can_edit')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('folder_id')->references('id')->on('document_folders')->onDelete('CASCADE')->onUpdate('NO ACTION');
            $table->foreign('viewer_user_id')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('created_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->unique(['folder_id', 'viewer_user_id'], 'document_folder_shares_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_folder_shares');
    }
};
