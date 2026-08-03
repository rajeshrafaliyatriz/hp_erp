<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive columns on s_competency_certifications for the Certification &
 * Compliance Center. Each one backs an element of that screen that previously
 * had no home on the table:
 *
 *  - certification_type   -> the "Certification Type" filter + the Overview
 *                            panel's "Certification Type" field.
 *  - verification_status  -> the "Pending Verification" KPI card and the
 *                            Verify / Reject row + bulk actions.
 *  - verified_by/_at      -> who signed the credential off and when.
 *  - notes                -> the Overview panel's Notes block.
 *  - requirement_id       -> links a held credential to the requirement it
 *                            satisfies (s_competency_certification_requirements).
 *
 * Purely additive and nullable (verification_status defaults to 'pending' only
 * for rows created from now on; existing rows stay NULL until backfilled by the
 * seeder). The four existing consumers - CommandCenterService counts,
 * EmployeeCompetencyProfileController::certifications, the command-center
 * quick-create POST and employeeProfileService.addCertification - all read or
 * write explicit column lists, so none of them sees a change.
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
        Schema::table('s_competency_certifications', function (Blueprint $table) {
            if (!$this->hasColumnPortable('s_competency_certifications', 'certification_type')) {
                $table->string('certification_type', 100)->nullable()->index()->after('issuing_body');
            }
            if (!$this->hasColumnPortable('s_competency_certifications', 'verification_status')) {
                // pending | verified | rejected
                $table->string('verification_status', 30)->nullable()->index()->after('status');
            }
            if (!$this->hasColumnPortable('s_competency_certifications', 'verified_by')) {
                $table->unsignedBigInteger('verified_by')->nullable()->after('verification_status');
            }
            if (!$this->hasColumnPortable('s_competency_certifications', 'verified_at')) {
                $table->dateTime('verified_at')->nullable()->after('verified_by');
            }
            if (!$this->hasColumnPortable('s_competency_certifications', 'notes')) {
                $table->text('notes')->nullable()->after('expiry_date');
            }
            if (!$this->hasColumnPortable('s_competency_certifications', 'requirement_id')) {
                $table->unsignedBigInteger('requirement_id')->nullable()->index()->after('competency_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('s_competency_certifications', function (Blueprint $table) {
            foreach (['certification_type', 'verification_status', 'verified_by', 'verified_at', 'notes', 'requirement_id'] as $column) {
                if ($this->hasColumnPortable('s_competency_certifications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
