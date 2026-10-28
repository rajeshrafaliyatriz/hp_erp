<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A new 1:many join table for "this uploaded document is attached to this
 * record" - deliberately NOT reusing document_library's own `source_system`/
 * `source_table`/`source_id` columns, despite how tempting that looks.
 *
 * Those columns carry `unique(source_system, source_table, source_id)`
 * (see create_document_library_table.php) and their one real writer
 * (DocumentIndexer::index()) does an `updateOrInsert` keyed on exactly that
 * triple, because the schema's own docblock states the invariant plainly:
 * "One index record per source row, not many." That's a 1:1 federated-index
 * pointer (a document that still physically lives in another feature's own
 * table), not a 1:many attachment mechanism - reusing it here would
 * duplicate-key-error, or silently overwrite the first file, the second
 * time anyone attached a document to the same CRM record.
 *
 * This table is the correct, cheap fix: one row per (document, record)
 * pair, matching this CRM subsystem's own already-proven polymorphic-pivot
 * shape (crm_campaign_targets' target_type/target_id). CRM-native uploads
 * leave document_library.source_* fully NULL (a true native upload, same
 * as any personnel upload) and get a row here instead. As a side benefit,
 * one uploaded file can show on more than one related record (e.g. a
 * Contact and its parent Organization) if ever wanted - the single-pair
 * `source_table`/`source_id` design could never express that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_library_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('document_id')->index();
            $table->string('linked_type', 40);
            $table->unsignedBigInteger('linked_id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['document_id', 'linked_type', 'linked_id']);
            $table->index(['linked_type', 'linked_id']);

            $table->foreign('document_id')->references('id')->on('document_library')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_library_links');
    }
};
