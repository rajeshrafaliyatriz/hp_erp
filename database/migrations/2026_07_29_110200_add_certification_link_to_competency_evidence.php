<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the existing Evidence Repository (s_competency_evidence) also serve as
 * the document store for the Certification & Compliance Center, instead of
 * creating a second near-identical table.
 *
 * s_competency_evidence already models "per-employee proof items (certificates,
 * projects, documents, endorsements) optionally tied to a competency" with a
 * link + verification status - a certificate PDF is exactly that. It was only
 * missing (a) which certification the proof belongs to and (b) somewhere to
 * record an uploaded file rather than an external URL.
 *
 * Additive and nullable, so the Employee Profile's Evidence tab (the existing
 * consumer) is unaffected; rows created from the certification panel simply
 * also surface there, which is the intended cross-screen behaviour.
 */
return new class extends Migration
{
    /**
     * Portable column check.
     *
     * Schema::hasColumn() asks information_schema for `generation_expression`,
     * a column that only exists on MySQL >= 5.7.6 / MariaDB >= 10.2. The
     * production server runs an older engine, so that call dies there with
     * "1054 Unknown column 'generation_expression'" even though the migration
     * itself is fine. SHOW COLUMNS has existed in every MySQL/MariaDB release,
     * so this behaves identically everywhere.
     */
    private function hasColumnPortable(string $table, string $column): bool
    {
        return count(DB::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column])) > 0;
    }

    public function up(): void
    {
        Schema::table('s_competency_evidence', function (Blueprint $table) {
            if (!$this->hasColumnPortable('s_competency_evidence', 'certification_id')) {
                $table->unsignedBigInteger('certification_id')->nullable()->index()->after('competency_id');
            }
            if (!$this->hasColumnPortable('s_competency_evidence', 'file_name')) {
                $table->string('file_name', 191)->nullable()->after('link');
            }
            if (!$this->hasColumnPortable('s_competency_evidence', 'file_path')) {
                $table->string('file_path', 500)->nullable()->after('file_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('s_competency_evidence', function (Blueprint $table) {
            foreach (['certification_id', 'file_name', 'file_path'] as $column) {
                if ($this->hasColumnPortable('s_competency_evidence', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
