<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE TWO REGISTRATION FIELDS AN INDIAN COMPANY HAS AND THIS PRODUCT DID NOT.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ORGANISATION TYPE WAS A HARDCODED STRING IN A REACT COMPONENT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `organization-information.tsx` declared:
 *
 *     organizationType: 'Private Limited',
 *
 * as a CONSTANT. It rendered as a badge at the top of the organisation profile
 * for every tenant on the platform, the edit panel offered a dropdown of four
 * options, and the chosen value was discarded - the save never sent it and the
 * server had no field to receive it.
 *
 * So twelve organisations were all labelled "Private Limited", editing it looked
 * like it worked, and the choice vanished on reload.
 *
 * ── AND THERE WAS NO UDYAM NUMBER ANYWHERE ─────────────────────────────────
 *
 * `org_details` carries CIN, GSTIN and PAN. A search for udyam/udyog/msme across
 * both repositories returns zero hits. For an Indian employer the Udyam
 * registration is the one that proves MSME status, so its absence is a gap in the
 * statutory record rather than a nice-to-have.
 *
 * ── WHY NOT A LOOKUP TABLE FOR THE TYPE ───────────────────────────────────
 *
 * Legal forms are a closed, stable, country-level set - Private Limited, LLP,
 * Partnership and so on - not tenant data. A table would need seeding, a
 * controller, a route and a cache, and would still be edited by nobody. The
 * validator's `Rule::in()` on the controller is the same guarantee with none of
 * that, and it is where the existing `DATE_FORMATS` / `WEEK_STARTS` choices already
 * live.
 *
 * Industry is the opposite case and is NOT touched here: `s_industries` already
 * exists as a 43-row taxonomy with a live endpoint, and the screen simply never
 * called it.
 *
 * ── MariaDB 10.1 ON LIVE ───────────────────────────────────────────────────
 *
 * `Schema::hasColumn()` asks for `information_schema.columns.generation_expression`,
 * which 10.1 does not have, and THROWS. Raw SQL, as everywhere else here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tablePresent('org_details')) {
            return;
        }

        Schema::table('org_details', function (Blueprint $table) {
            if (!$this->columnPresent('org_details', 'organization_type')) {
                /*
                 * Nullable with no default, deliberately. Defaulting every existing
                 * organisation to "Private Limited" would bake in the very fiction
                 * this removes - twelve tenants asserted to be something nobody
                 * ever told us. Blank means "not stated", which is true.
                 */
                $table->string('organization_type', 64)->nullable()->after('legal_name');
            }

            if (!$this->columnPresent('org_details', 'udyam_registration_no')) {
                // Udyam numbers are UDYAM-XX-00-0000000 - 19 characters. 32 leaves
                // room for formatting without inviting a paragraph.
                $table->string('udyam_registration_no', 32)->nullable()->after('pan');
            }
        });
    }

    /**
     * Drops both columns, and that loses whatever was typed into them.
     *
     * Stated rather than implied: a Udyam number is transcribed off a government
     * certificate, and rolling this back discards it for every organisation that
     * had entered one. There is nothing to restore it from.
     */
    public function down(): void
    {
        if (!$this->tablePresent('org_details')) {
            return;
        }

        Schema::table('org_details', function (Blueprint $table) {
            foreach (['organization_type', 'udyam_registration_no'] as $column) {
                if ($this->columnPresent('org_details', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function tablePresent(string $table): bool
    {
        $found = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return (int) ($found->n ?? 0) > 0;
    }

    private function columnPresent(string $table, string $column): bool
    {
        $found = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return (int) ($found->n ?? 0) > 0;
    }
};
