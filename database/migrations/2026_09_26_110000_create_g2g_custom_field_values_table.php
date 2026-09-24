<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a custom field's ANSWERS live.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY A TABLE AND NOT A COLUMN
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * LMS K-12 stores these in real columns: `CustomFieldApiController::store()`
 * ends with `ALTER TABLE <table_name> ADD COLUMN <field_name> VARCHAR NULL`,
 * where `table_name` came from a free-text input validated only as
 * `required|string|max:50`. Any admin with add-rights can alter any table in
 * that schema, and because DDL is global while the field row is tenant-scoped,
 * one school's configuration silently changes every school's table.
 *
 * The column approach does have a genuine advantage, and it should be said
 * rather than glossed: a real column is joinable and reportable like any other,
 * which is why K-12's user report can select custom fields directly. This table
 * trades that for two things worth more here:
 *
 *   - no schema change per tenant per field, which is how a product ends up
 *     unable to run a migration across its estate;
 *   - no DDL reachable from an HTTP request at all.
 *
 * `tblcustom_fields` had ZERO rows on this database when this was written, so
 * there was no legacy data to stay compatible with and the choice was free.
 *
 * ── THE LEGACY BLADE SCREENS ARE UNAFFECTED ─────────────────────────────────
 *
 * `tbluserController` reads `tblcustom_fields` to render its own form and expects
 * the values in columns on `tbluser`. That path is untouched and still works for
 * any field that already has a column. It simply has no rows to render, because
 * nothing has ever created one. New fields defined through the platform console
 * are answered here instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_custom_field_values')) {
            return;
        }

        Schema::create('g2g_custom_field_values', function (Blueprint $table) {
            $table->bigIncrements('id');

            // NOT NULL. This table holds every tenant's answers, so it is the worst
            // possible place for an unscoped row. Resolved from the caller's token
            // at write time, never from request input.
            $table->unsignedBigInteger('sub_institute_id');

            // The field being answered. No foreign key: `tblcustom_fields` is
            // soft-deleted (`is_deleted='Y'`), so a cascade would never fire, and
            // an answer should outlive the field's removal — otherwise deleting a
            // field destroys the data captured under it with no way to recover it.
            $table->unsignedBigInteger('field_id');

            // Which record this answers for: `tbluser` + the employee's id. The
            // table name is carried rather than assumed, so a second record type
            // needs no schema change — only an allowlist entry.
            $table->string('record_table', 64);
            $table->unsignedBigInteger('record_id');

            /*
             * The answer, as text, whatever the field's type.
             *
             * A typed column per field type would be four nullable columns and a
             * rule about which one to read. The field's `field_type` already says
             * how to interpret this, and it is the only thing that can — a date
             * and a dropdown value are both strings until something knows better.
             */
            $table->text('value')->nullable();

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // One answer per field per record. This is what makes a save an upsert
            // rather than an append, and what stops two saves racing into two rows
            // that later reads would have to choose between.
            $table->unique(
                ['record_table', 'record_id', 'field_id'],
                'g2g_cfv_record_field_unique'
            );

            // "Every answer for this record", which is the only read the form does.
            $table->index(['record_table', 'record_id'], 'g2g_cfv_record_idx');

            // "Everything this tenant has captured", for a future export.
            $table->index(['sub_institute_id', 'field_id'], 'g2g_cfv_tenant_field_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_custom_field_values');
    }
};
