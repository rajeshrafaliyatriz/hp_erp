<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE ONE DOCUMENT TABLE.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY A NEW TABLE RATHER THAN MORE COLUMNS ON staff_document
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `staff_document` already carries two divergent storage-folder conventions
 * from two writers that each assumed they owned the convention (see
 * `EmployeeDocumentController`'s docblock). A content-searchable,
 * AI-classified, access-controlled document needs extracted text, a
 * visibility/ACL model, a processing pipeline and version history - none of
 * which `staff_document` has room for without becoming an unreadable pile of
 * nullable columns bolted onto a table that was never designed for them.
 *
 * `document_library` is the one table every document writer in this codebase
 * targets from here on: personnel uploads, payslips, offer letters, Form 16,
 * resumes, and - via a `source_system`/`source_table`/`source_id` pointer -
 * an indexed copy of documents that still physically live in another
 * feature's own table (onboarding, competency, task, offboarding, LMS
 * certificates), so one search surface can cover all of them without
 * rewriting five working features' storage and permission logic.
 *
 * `staff_document` is backfilled into this table once (see the companion
 * `documents:backfill-staff-documents` console command) and then frozen -
 * kept so nothing 404s mid-cutover, but no longer written to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_library', function (Blueprint $table) {
            $table->bigIncrements('id');

            // ── whose document, and in which organisation ──────────────────
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('owner_id')->nullable()->index();

            // ── the object itself ───────────────────────────────────────────
            $table->string('title', 191);
            $table->string('original_file_name', 255)->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            // SHA-256 is 64 hex chars. Indexed for exact-duplicate detection on
            // upload - the same bytes filed twice should be caught, not stored twice.
            $table->char('checksum_sha256', 64)->nullable()->index();
            $table->string('storage_path', 512)->nullable();
            $table->unsignedInteger('current_version')->default(1);

            // ── classification ──────────────────────────────────────────────
            // 'personnel' | 'organization'. The coarse split the UI filters on
            // first (My Documents vs the organisation-wide library).
            $table->string('category', 32)->default('personnel')->index();
            // Open vocabulary (config/documents.php lists the known values),
            // not a locked lookup table - student_document_type has no seeder
            // anywhere in this codebase and its live contents are unknowable
            // from source control, which is precisely the trap this avoids.
            $table->string('document_type', 64)->nullable()->index();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->date('document_date')->nullable();
            $table->string('period_label', 20)->nullable(); // e.g. "2025-26"
            $table->string('subject', 191)->nullable();
            // JSON-encoded (application-level, via json_encode/json_decode),
            // not the native `json` column type: this table is also migrated
            // onto the `live` connection (MariaDB 10.1.48, see
            // config/database.php), which predates `JSON` as a column type
            // entirely and 1064s on it. longText is portable to both and
            // behaves identically here since nothing reads/writes it through
            // Eloquent casts - every caller is DB::table() with explicit
            // json_encode()/json_decode(), same convention DocumentAccess uses.
            $table->longText('tags')->nullable();
            $table->longText('keywords')->nullable();

            // ── content, for the search this feature exists to provide ─────
            $table->longText('extracted_text')->nullable();
            $table->text('summary')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();

            // ── who may see it ───────────────────────────────────────────────
            // 'private' | 'department' | 'organization'.
            $table->string('visibility', 20)->default('private')->index();
            // ["user:5","role:3","dept:2"] - materialized from visibility +
            // department_id + permissions whenever any of them changes, so a
            // list query can filter on this JSON array directly instead of
            // re-deriving the ACL per row. See DocumentAccess::visibleTo().
            // longText, not json - see the note on `tags` above.
            $table->longText('view_principals')->nullable();
            // Per-user/role/department overrides: view/edit/download/share.
            $table->longText('permissions')->nullable();

            // ── the async pipeline ───────────────────────────────────────────
            // pending -> processing -> ready_for_review -> done | failed.
            // Only 'done' rows are returned by search/browse - see
            // DocumentSearchService. A mis-classified document does not
            // surface before a human has glanced at it.
            $table->string('processing_status', 20)->default('pending')->index();
            $table->text('processing_error')->nullable();
            $table->longText('warnings')->nullable();

            // ── federated indexing (see §4 of the plan) ─────────────────────
            // Null = a native document_library upload. Set = this row is an
            // INDEX of a document that still physically lives in another
            // feature's own table; storage_path stays null and downloads
            // redirect back through that feature's own controller, so this
            // table never duplicates another domain's access logic.
            $table->string('source_system', 40)->nullable()->index();
            $table->string('source_table', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->unsignedBigInteger('deleted_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('owner_id')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('created_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('updated_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('deleted_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');

            // One index record per source row, not many - an onboarding
            // document that changes gets its existing index row updated.
            $table->unique(['source_system', 'source_table', 'source_id'], 'document_library_source_unique');
        });

        // FULLTEXT is created separately: Blueprint::fullText() needs the
        // table to exist first on some MariaDB/InnoDB combinations, and a
        // dedicated statement is easier to verify ran at all. This codebase
        // has already shipped a bug where code referenced a FULLTEXT index no
        // migration ever created (TaskListController's search once MATCH()'d
        // a non-existent index and 500ed on first use) - the index is created
        // HERE, not assumed.
        Schema::table('document_library', function (Blueprint $table) {
            $table->fullText(['title', 'original_file_name', 'subject'], 'document_library_title_fulltext');
            $table->fullText('extracted_text', 'document_library_content_fulltext');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_library');
    }
};
