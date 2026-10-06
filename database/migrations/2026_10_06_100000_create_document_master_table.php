<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IDMS: one row per document, current state only. History lives in
 * document_history. Guarded so re-running on a database that already has the
 * table is a no-op.
 *
 * Uses no SQL JSON functions: this server has neither JSON_OVERLAPS nor
 * JSON_CONTAINS, so the visibleTo scope matches the stored JSON text with
 * LIKE (see DocumentMaster::jsonListLike). The fulltext indexes use the ngram
 * parser when available.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_master')) {
            return;
        }

        Schema::create('document_master', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sub_institute_id')->index();
            $table->string('title', 255);
            $table->string('original_file_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum_sha256', 64)->index();
            $table->string('storage_path', 500);
            $table->string('preview_path', 500)->nullable();
            $table->unsignedInteger('current_version')->default(1);

            $table->string('document_type', 100)->nullable()->index();
            $table->string('category', 100)->nullable();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->string('subject', 255)->nullable();
            $table->date('document_date')->nullable();
            $table->string('academic_year', 20)->nullable()->index();
            $table->string('organization', 255)->nullable();
            $table->string('project', 255)->nullable();
            $table->enum('lifecycle_status', ['active', 'expired', 'archived', 'filed'])->default('active')->index();
            $table->text('summary')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();

            $table->json('people')->nullable();
            $table->json('keywords')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->text('tags_text')->nullable();

            $table->json('tags')->nullable();
            $table->json('tag_names')->nullable();

            $table->binary('embedding')->nullable();

            $table->unsignedBigInteger('owner_id')->index();
            $table->enum('visibility', ['private', 'department', 'organization'])->default('private');
            $table->json('view_principals')->nullable();
            $table->json('permissions')->nullable();

            $table->enum('processing_status', ['pending', 'processing', 'ready_for_review', 'done', 'failed'])->default('pending')->index();
            $table->text('processing_error')->nullable();
            $table->json('warnings')->nullable();

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        try {
            DB::statement('ALTER TABLE document_master ADD FULLTEXT idx_doc_fulltext (title, original_file_name, tags_text, subject, organization) WITH PARSER ngram');
            DB::statement('ALTER TABLE document_master ADD FULLTEXT idx_doc_extracted_text (extracted_text) WITH PARSER ngram');
        } catch (\Throwable $e) {
            DB::statement('ALTER TABLE document_master ADD FULLTEXT idx_doc_fulltext (title, original_file_name, tags_text, subject, organization)');
            DB::statement('ALTER TABLE document_master ADD FULLTEXT idx_doc_extracted_text (extracted_text)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_master');
    }
};
