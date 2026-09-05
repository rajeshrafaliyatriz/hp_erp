<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Where IT provisioning, benefits and policy sign-off actually get recorded.
 *
 *   php artisan migrate --database=mysql --path=database/migrations/2026_09_04_130000_create_onboarding_capture_tables.php
 *   php artisan migrate --database=live  --path=database/migrations/2026_09_04_130000_create_onboarding_capture_tables.php
 *
 * ── WHY THESE THREE, AND NOT THE OTHER TWO ──────────────────────────────────
 *
 * The onboarding screen has five workstreams. Two of them already have a home
 * and get no table here:
 *
 *   Payroll Setup    - `tbluser` already carries bank_name, account_no,
 *                      ifsc_code, pan_no, aadhar_no, pf_no, esic_no, uan_no and
 *                      the deduction flags. A second copy would be a second
 *                      truth about somebody's UAN.
 *   Learning         - lms_course_enroll holds 1,468 rows and LearningAssigner
 *                      already writes them on employee.role_assigned. A parallel
 *                      list of "what this person must learn" is exactly the
 *                      drift this codebase keeps producing.
 *
 * The three below had nothing at all: no asset register, no benefits table, no
 * acknowledgement anywhere. `master_compliance` is an ORGANISATIONAL register
 * (standards with a recurring frequency), not a person signing a policy, and
 * `department_policies` can publish a policy but nobody can sign it.
 *
 * ── AGAINST LIVE'S LIMITS ───────────────────────────────────────────────────
 *
 * MariaDB 10.1, InnoDB, ROW_FORMAT=Compact: 767-byte index prefix, 64-character
 * identifiers. No `json` column type exists there. VARCHAR + a PHP const, never
 * ENUM, so adding an asset type later is not an ALTER on live.
 *
 * Widest index below is pol_ack_emp_policy_version_unique:
 *   employee_id 8 + policy_key VARCHAR(100) 400 + policy_version VARCHAR(20) 80
 *   = 488 bytes. Longest identifier is 33 characters.
 *
 * Guarded on table existence, so re-running is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * WHAT WAS ISSUED, AND WHETHER IT CAME BACK.
         *
         * `serial_no` is what makes this a register rather than a checkbox - "IT
         * provisioning done" tells you nothing when a laptop goes missing.
         * `returned_on` is what lets OFFBOARDING reclaim it later: the exit
         * clearance list already has a "Laptop Return" item that today has no
         * idea which laptop.
         */
        if (!$this->tableExists('talent_onboarding_assets')) {
            DB::statement(
                'CREATE TABLE `talent_onboarding_assets` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `journey_id` BIGINT UNSIGNED NULL,
                    `employee_id` BIGINT UNSIGNED NULL,
                    `asset_type` VARCHAR(40) NOT NULL,
                    `make_model` VARCHAR(191) NULL,
                    `serial_no` VARCHAR(100) NULL,
                    `issued_on` DATE NULL,
                    `returned_on` DATE NULL,
                    `condition_note` TEXT NULL,
                    `status` VARCHAR(20) NOT NULL DEFAULT "issued",
                    `created_by` BIGINT UNSIGNED NULL,
                    `updated_by` BIGINT UNSIGNED NULL,
                    `deleted_by` BIGINT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL,
                    `updated_at` TIMESTAMP NULL,
                    `deleted_at` TIMESTAMP NULL,
                    PRIMARY KEY (`id`),
                    KEY `onb_asset_tenant_emp_idx` (`sub_institute_id`, `employee_id`),
                    KEY `onb_asset_journey_idx` (`journey_id`),
                    KEY `onb_asset_serial_idx` (`serial_no`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        /*
         * WHAT THE EMPLOYEE IS COVERED BY, AND WHO INHERITS IT.
         *
         * `nominee_name` is not decoration: a group life policy with no nominee
         * recorded is the single most expensive gap on this list, and it is
         * always discovered at the worst possible moment.
         *
         * coverage_amount is DECIMAL, never a float - money.
         */
        if (!$this->tableExists('talent_employee_benefits')) {
            DB::statement(
                'CREATE TABLE `talent_employee_benefits` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `journey_id` BIGINT UNSIGNED NULL,
                    `employee_id` BIGINT UNSIGNED NOT NULL,
                    `benefit_type` VARCHAR(40) NOT NULL,
                    `provider` VARCHAR(191) NULL,
                    `policy_no` VARCHAR(100) NULL,
                    `coverage_amount` DECIMAL(12,2) NULL,
                    `effective_from` DATE NULL,
                    `nominee_name` VARCHAR(191) NULL,
                    `nominee_relation` VARCHAR(50) NULL,
                    `status` VARCHAR(20) NOT NULL DEFAULT "enrolled",
                    `created_by` BIGINT UNSIGNED NULL,
                    `updated_by` BIGINT UNSIGNED NULL,
                    `deleted_by` BIGINT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL,
                    `updated_at` TIMESTAMP NULL,
                    `deleted_at` TIMESTAMP NULL,
                    PRIMARY KEY (`id`),
                    KEY `emp_benefit_tenant_emp_idx` (`sub_institute_id`, `employee_id`),
                    KEY `emp_benefit_journey_idx` (`journey_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        /*
         * WHO SIGNED WHAT, AND WHICH VERSION OF IT.
         *
         * `policy_version` is the column that makes this worth having.
         * Acknowledging v1 of a handbook is not acknowledging v3, and without
         * the version a re-issued policy silently looks signed by everyone who
         * ever signed the old one - which is precisely the claim a compliance
         * audit tests. `department_policies` already carries a `version`, so
         * this records the one that was actually shown.
         *
         * The unique key is (employee, policy, version): signing the same
         * version twice is not a second fact, but signing v3 after v1 is.
         */
        if (!$this->tableExists('talent_policy_acknowledgements')) {
            DB::statement(
                'CREATE TABLE `talent_policy_acknowledgements` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `journey_id` BIGINT UNSIGNED NULL,
                    `employee_id` BIGINT UNSIGNED NOT NULL,
                    `policy_key` VARCHAR(100) NOT NULL,
                    `policy_title` VARCHAR(191) NULL,
                    `policy_version` VARCHAR(20) NOT NULL DEFAULT "1.0",
                    `acknowledged_at` TIMESTAMP NULL,
                    `acknowledged_ip` VARCHAR(45) NULL,
                    `created_by` BIGINT UNSIGNED NULL,
                    `updated_by` BIGINT UNSIGNED NULL,
                    `deleted_by` BIGINT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL,
                    `updated_at` TIMESTAMP NULL,
                    `deleted_at` TIMESTAMP NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `pol_ack_emp_policy_version_unique` (`employee_id`, `policy_key`, `policy_version`),
                    KEY `pol_ack_tenant_emp_idx` (`sub_institute_id`, `employee_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
    }

    public function down(): void
    {
        /*
         * Dropped only when empty. An asset register or a signed acknowledgement
         * is a record somebody may need to produce years later; a rollback that
         * destroys one to tidy the schema is not a rollback, it is data loss.
         */
        foreach ([
            'talent_policy_acknowledgements',
            'talent_employee_benefits',
            'talent_onboarding_assets',
        ] as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $rows = (int) DB::table($table)->count();

            if ($rows > 0) {
                throw new RuntimeException(
                    'Refusing to drop ' . $table . ': it holds ' . $rows . ' row(s). '
                    . 'Export or delete them first if this is really intended.'
                );
            }

            DB::statement('DROP TABLE `' . $table . '`');
        }
    }

    /** information_schema, not Schema::hasTable() - that throws on live. */
    private function tableExists(string $table): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ));
    }
};
