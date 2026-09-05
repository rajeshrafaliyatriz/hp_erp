<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A candidate, kept as a person rather than as a row per application.
 *
 *   php artisan migrate --database=mysql --path=database/migrations/2026_09_03_130000_create_talent_candidates.php
 *   php artisan migrate --database=live  --path=database/migrations/2026_09_03_130000_create_talent_candidates.php
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * talent_job_applications stores the applicant's name, email, mobile, CV path
 * and salary expectation on EVERY application. Somebody who applies for three
 * roles is three unrelated rows; there is no way to ask "have we seen this
 * person before", and when they apply again their CV from last time is
 * unreachable because nothing links the rows together.
 *
 * This table holds the person once per organisation. Applications point at it.
 * A returning applicant updates their record instead of creating a stranger.
 *
 * ── CONSENT IS A COLUMN, NOT AN ASSUMPTION ──────────────────────────────────
 *
 * Retaining someone's CV and contact details beyond the role they applied for
 * is a different thing from processing their application, and they have to say
 * yes to it. `consent_to_retain` defaults to 0 and is set only from an explicit
 * choice on the public form; `consent_at` records when. A candidate who does not
 * consent still gets a record - the application has to work - but the record is
 * flagged so it can be excluded from any future-role search or purged.
 *
 * ── THE INDEX, AND WHY THE EMAIL IS HASHED ──────────────────────────────────
 *
 * The natural key is (sub_institute_id, email). email is varchar(255), so that
 * index would be 8 + 255*4 = 1028 bytes - well over the 767-byte prefix cap that
 * live's ROW_FORMAT=Compact imposes, and it would fail on live while passing on
 * dev. Shortening the column risks truncating a real address.
 *
 * So the email is stored in full and UNINDEXED, and a sha256 of its lowercased,
 * trimmed form is stored alongside in a fixed char(64). The unique index is
 * 8 + 64*4 = 264 bytes. Computed in PHP, not as a generated column: MariaDB
 * 10.1.48 predates the syntax used on the newer host, and a migration must apply
 * identically to both.
 *
 * ── LIVE CONSTRAINTS ────────────────────────────────────────────────────────
 *
 *   - No json column. No ENUM: `source` is VARCHAR + a controller const.
 *   - Nothing indexes a varchar(191) or wider.
 *   - Every index and foreign key named explicitly, all well under 64 chars.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('talent_candidates')) {
            Schema::create('talent_candidates', function (Blueprint $table) {
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';

                $table->bigIncrements('id');

                // Root aggregate: a candidate exists before any application does.
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('syear', 50);

                $table->string('email', 255);
                /** sha256(lower(trim(email))). Indexed in place of the address itself. */
                $table->char('email_key', 64);

                $table->string('first_name', 100)->nullable();
                $table->string('middle_name', 100)->nullable();
                $table->string('last_name', 100)->nullable();
                $table->string('mobile', 20)->nullable();
                $table->string('current_location', 255)->nullable();

                $table->string('experience', 100)->nullable();
                $table->string('education', 255)->nullable();
                $table->decimal('expected_salary', 12, 2)->nullable();
                $table->text('skills')->nullable();
                $table->text('certifications')->nullable();

                /** The most recent CV. Past ones stay on their own application rows. */
                $table->string('resume_path', 500)->nullable();

                /** Explicit, and never assumed. See the note above. */
                $table->boolean('consent_to_retain')->default(0);
                $table->dateTime('consent_at')->nullable();

                /** CareersController::CANDIDATE_SOURCES - careers | internal */
                $table->string('source', 30)->default('careers');

                $table->unsignedInteger('applications_count')->default(0);
                $table->dateTime('last_applied_at')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['sub_institute_id', 'email_key'], 'tcand_tenant_email_unique');
                $table->index(['sub_institute_id', 'syear'], 'tcand_tenant_idx');
                $table->index(['sub_institute_id', 'consent_to_retain'], 'tcand_consent_idx');
            });
        }

        // The link back. Nullable, because every application that already exists
        // predates this table and is backfilled below rather than guessed at.
        if (!$this->hasColumn('talent_job_applications', 'candidate_id')) {
            DB::statement('ALTER TABLE `talent_job_applications` ADD COLUMN `candidate_id` BIGINT UNSIGNED NULL AFTER `job_id`');
            DB::statement('ALTER TABLE `talent_job_applications` ADD INDEX `tapp_candidate_idx` (`candidate_id`)');
        }

        $this->backfill();
    }

    public function down(): void
    {
        if ($this->hasColumn('talent_job_applications', 'candidate_id')) {
            DB::statement('ALTER TABLE `talent_job_applications` DROP INDEX `tapp_candidate_idx`');
            DB::statement('ALTER TABLE `talent_job_applications` DROP COLUMN `candidate_id`');
        }

        if ($this->tableExists('talent_candidates')) {
            Schema::drop('talent_candidates');
        }
    }

    /**
     * Fold the applications that already exist into one record per person.
     *
     * consent_to_retain stays 0 for every backfilled row: nobody who applied
     * before this table existed was ever asked. They are recorded so a recruiter
     * can see a repeat applicant, and excluded from anything that treats consent
     * as given.
     */
    private function backfill(): void
    {
        $rows = DB::table('talent_job_applications')
            ->whereNull('deleted_at')
            ->whereNull('candidate_id')
            ->orderBy('id')
            ->get([
                'id', 'sub_institute_id', 'email', 'first_name', 'middle_name', 'last_name',
                'mobile', 'current_location', 'experience', 'education', 'expected_salary',
                'skills', 'certifications', 'resume_path', 'applied_date', 'created_at',
            ]);

        foreach ($rows as $row) {
            $email = trim((string) $row->email);
            if ($email === '' || !$row->sub_institute_id) {
                continue;
            }

            $key = hash('sha256', mb_strtolower($email));

            $existing = DB::table('talent_candidates')
                ->where('sub_institute_id', $row->sub_institute_id)
                ->where('email_key', $key)
                ->first(['id', 'applications_count', 'last_applied_at']);

            $applied = $row->applied_date ?: $row->created_at;

            if ($existing) {
                DB::table('talent_candidates')->where('id', $existing->id)->update([
                    'applications_count' => (int) $existing->applications_count + 1,
                    'last_applied_at' => max((string) $existing->last_applied_at, (string) $applied) ?: null,
                    'updated_at' => now(),
                ]);
                $candidateId = $existing->id;
            } else {
                $candidateId = DB::table('talent_candidates')->insertGetId([
                    'sub_institute_id' => $row->sub_institute_id,
                    'syear' => (string) date('Y'),
                    'email' => $email,
                    'email_key' => $key,
                    'first_name' => $row->first_name,
                    'middle_name' => $row->middle_name,
                    'last_name' => $row->last_name,
                    'mobile' => $row->mobile,
                    'current_location' => $row->current_location,
                    'experience' => $row->experience,
                    'education' => $row->education,
                    'expected_salary' => $row->expected_salary,
                    'skills' => $row->skills,
                    'certifications' => $row->certifications,
                    'resume_path' => $row->resume_path,
                    'consent_to_retain' => 0,
                    'source' => 'careers',
                    'applications_count' => 1,
                    'last_applied_at' => $applied,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('talent_job_applications')->where('id', $row->id)->update(['candidate_id' => $candidateId]);
        }
    }

    /** Schema::hasTable() throws on live (MariaDB 10.1.48); read the catalogue. */
    private function tableExists(string $table): bool
    {
        return (int) (DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        )->c ?? 0) > 0;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) (DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->c ?? 0) > 0;
    }
};
