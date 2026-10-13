<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DRIVE-STYLE FOLDERS FOR document_library.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY `parent_id` IS NULLABLE, NOT `0`-FOR-ROOT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `tblmenumaster` (this codebase's existing self-referencing tree) uses
 * `parent_id = 0` for a root row, which is why IT has no foreign key on its
 * own `parent_id` - there is no row with `id = 0` for `0` to reference.
 * `document_library` itself already establishes the newer convention this
 * table follows instead: a nullable FK, `NULL` meaning "no parent", which
 * lets a REAL foreign-key constraint exist. The tree is still built the same
 * way `tblmenumasterG2gController::buildMenuTree()` already does - one
 * query, `groupBy('parent_id')`, a plain-PHP recursive walk - not a
 * recursive SQL CTE (no precedent for one anywhere in this codebase, and the
 * `live` connection's older MariaDB is a reason to keep it that way).
 *
 * ── NO UNIQUENESS ON (parent_id, name) ───────────────────────────────────────
 *
 * Two sibling folders sharing a name is allowed on purpose. A DB constraint
 * here would turn the recursive folder-upload flow's "find or create each
 * path segment" into a check-then-insert race (two concurrent uploads
 * resolving the same relative path, or two "New Folder" clicks) that throws
 * a duplicate-key error mid-batch instead of just reusing the existing
 * folder. The find-or-create query itself is what actually prevents
 * accidental duplication during normal use.
 *
 * ── SOFT-DELETE EXISTS; A RESTORE UI DOES NOT, YET ───────────────────────────
 *
 * `softDeletes()` is here because it is cheap to add now and expensive to
 * retrofit later, matching every other table in this feature. But v1 ships
 * no folder-trash view and no folder-restore endpoint - `DocumentFolderController::destroy()`
 * refuses (422) to soft-delete a non-empty folder rather than orphaning its
 * contents, so there is nothing yet that NEEDS restoring. A real "delete
 * folder and everything in it" flow is future work, not silently dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id')->index();
            // Nullable, same reasoning as document_library.owner_id: an
            // org-wide folder HR creates for the whole tenant is not
            // conceptually "owned" by one person.
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();

            $table->string('name', 191);
            // Same three-value enum as document_library.visibility, on
            // purpose - DocumentAccess's folder-aware methods reuse the
            // exact ACL shape rather than restating it for a second table.
            $table->string('visibility', 20)->default('private')->index();
            $table->unsignedInteger('sort_order')->default(0);

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->unsignedBigInteger('deleted_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('owner_id')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('parent_id')->references('id')->on('document_folders')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('created_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('updated_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('deleted_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_folders');
    }
};
