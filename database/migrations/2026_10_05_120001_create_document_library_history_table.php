<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VERSIONS AND AUDIT TRAIL FOR document_library, IN ONE APPEND-ONLY TABLE.
 *
 * One table rather than two, the way the reference implementation (next_lms_erp's
 * IDMS, commit a756b7e9e) found works: a version row and an audit row are both
 * "something happened to this document, keep a durable note of it" - they differ
 * only in which columns they fill in, not in shape. `entry_type` tells them apart.
 *
 * `version` rows: version_number, storage_path, checksum_sha256, size, change_note.
 * `audit` rows: action (viewed/downloaded/updated/shared/...), details (json),
 * ip_address.
 *
 * Append-only: nothing here is ever updated or deleted by application code, so a
 * row written at upload time is still the true record of what that version was,
 * even after the document has since moved to version 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_library_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('document_id')->index();
            $table->string('entry_type', 10)->index(); // 'version' | 'audit'

            // version rows
            $table->unsignedInteger('version_number')->nullable();
            $table->string('storage_path', 512)->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('change_note', 255)->nullable();

            // audit rows
            $table->string('action', 40)->nullable();
            // longText, not json - see document_library migration's note on
            // the `live` (MariaDB 10.1.48) connection having no JSON type.
            $table->longText('details')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->foreign('document_id')->references('id')->on('document_library')->onDelete('CASCADE')->onUpdate('NO ACTION');
            $table->foreign('created_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_library_history');
    }
};
