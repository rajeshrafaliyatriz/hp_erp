<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2. Three columns `FieldConfigController::validateField()` could not enforce.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS ALTERS tblcustom_fields RATHER THAN ADDING A SATELLITE TABLE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `g2g_custom_field_values` exists as a satellite table instead of real columns
 * because VALUES are per-record and per-tenant — one row per answer, and a column per
 * custom field would mean a schema change per tenant per field. A validation RULE is
 * different: it belongs to the field definition itself, one rule per field, which is
 * exactly what a column on `tblcustom_fields` already is for every other property a
 * field has (`required`, `sort_order`, `field_message`...). A second table keyed 1:1 on
 * `field_id` would just be this table with extra steps.
 *
 * `tblcustom_fields` is safe to alter here for the same reason Round 1's docblock on
 * `FieldConfigController` already establishes: it "has existed for a year with no API in
 * front of it" — nothing outside this Platform Services work reads or writes it, so three
 * new nullable columns cannot break a consumer that does not exist.
 *
 * ── WHY min_value/max_value ARE STRINGS, NOT INTEGERS ───────────────────────
 *
 * A `number` field's min/max are stored and compared as they are typed — casting to int
 * would silently truncate a fractional bound like "0.5", and this table already stores
 * every other value-shaped thing as a string (`file_size_max` beside it does the same).
 * `FieldConfigController` and `CustomFieldValues` are what give the string a numeric
 * meaning, not the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tblcustom_fields')) {
            return;
        }

        Schema::table('tblcustom_fields', function (Blueprint $table) {
            // number fields only. Null means "no lower/upper bound", not zero.
            if (! self::hasColumn('tblcustom_fields', 'min_value')) {
                $table->string('min_value', 32)->nullable()->after('file_size_max');
            }

            if (! self::hasColumn('tblcustom_fields', 'max_value')) {
                $table->string('max_value', 32)->nullable()->after('min_value');
            }

            // text/textarea fields only. A PHP-compatible PCRE pattern WITHOUT its own
            // delimiters — FieldConfigController wraps it in '#...#u' before use, the
            // same way a stored search pattern would be, so this column never has to
            // guess what delimiter the admin who typed it meant.
            if (! self::hasColumn('tblcustom_fields', 'validation_pattern')) {
                $table->string('validation_pattern', 191)->nullable()->after('max_value');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tblcustom_fields')) {
            return;
        }

        foreach (['min_value', 'max_value', 'validation_pattern'] as $column) {
            if (self::hasColumn('tblcustom_fields', $column)) {
                Schema::table('tblcustom_fields', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /**
     * Whether a column exists, on ANY MySQL or MariaDB version.
     *
     * Same portable check as Round 1's migrations — see
     * `2026_09_26_100000_add_platform_chain_columns_to_leave_approval_steps.php` for why
     * `Schema::hasColumn()` itself is unsafe on the MariaDB 10.1 host this application
     * also runs on.
     */
    private static function hasColumn(string $table, string $column): bool
    {
        try {
            $rows = \Illuminate\Support\Facades\DB::select(
                'select column_name from information_schema.columns '
                . 'where table_schema = database() and table_name = ? and column_name = ?',
                [$table, $column]
            );

            return $rows !== [];
        } catch (\Throwable) {
            return false;
        }
    }
};
