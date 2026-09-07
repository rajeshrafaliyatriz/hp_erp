-- =====================================================================
-- REVERSAL — bringing 128.199.17.97/hp_erp (MariaDB 10.1.48) up to date
-- 2026-09-07
--
-- This host was 65 migrations behind and could not be migrated: `migrate`
-- died on the first one ("Table 's_user_skill_application' already exists")
-- and applied nothing. The cause was drift, not a bad migration — tables and
-- columns had been applied by hand over months without the `migrations` table
-- being told.
--
-- Resolved in three batches. Undo them in DESCENDING order.
-- =====================================================================


-- ---------------------------------------------------------------------
-- BATCH 179 — the eight migrations that actually executed
-- ---------------------------------------------------------------------
--   2026_07_30_120000_clear_legacy_plaintext_passwords
--   2026_08_11_000300_correct_notification_action_paths
--   2026_08_11_000400_declare_competency_referent
--   2026_08_11_000500_declare_remaining_referents
--   2026_08_11_000700_suggested_course_task_optional
--   2026_09_07_100000_close_orphaned_approval_steps      (no-op here: 0 orphans)
--   2026_09_07_110000_canonicalise_payroll_month
--   2026_09_07_120000_seed_flat_cap_behaviour            (no-op here: no listed tenant)
--
--   php artisan migrate:rollback --database=live --step=1
--
-- NOT REVERSIBLE BY THAT COMMAND:
--
--   * clear_legacy_plaintext_passwords wiped `tbluser.plain_password` on
--     296 rows. Those values are GONE and cannot be restored — that is the
--     point of the migration. Nobody's login is affected; `password` (the
--     hashed column) was never touched. Verified before: 296 non-null.
--     Verified after: 0.
--
--   * canonicalise_payroll_month collapsed 22 payslip rows to 6. To restore
--     the 17 original 'july' rows verbatim, use the companion script:
--         REVERSAL-2026-09-07-payroll-month-canonical-128.199.17.97.sql
--     Run that BEFORE dropping the unique index, or the inserts will be
--     rejected by it.


-- ---------------------------------------------------------------------
-- BATCH 178 — one table created
-- ---------------------------------------------------------------------
--   2025_12_31_063039_create_user_onboarding_status_table
--
-- (It ran, then the batch aborted on the next migration, so it sits alone.)
--
--   php artisan migrate:rollback --database=live --step=1


-- ---------------------------------------------------------------------
-- BATCH 177 — bookkeeping only. NO SCHEMA WAS TOUCHED.
-- ---------------------------------------------------------------------
-- 56 migrations recorded as run because their tables and columns were
-- verified already present on this host. Nothing was created, altered or
-- dropped; only the `migrations` table was written.
--
-- Undoing this restores the "65 pending" state and nothing else:

--   DELETE FROM migrations WHERE batch = 177;

-- Do NOT run `migrate:rollback` on batch 177. Its down() methods would drop
-- 56 migrations' worth of tables and columns that were never created by this
-- session and that live data depends on.


-- =====================================================================
-- BEFORE / AFTER, measured
-- =====================================================================
--                                   before      after
--   migrations recorded                243        308
--   tbluser.plain_password set         296          0
--   employee_monthly_salary_data        22          6
--       month='july'                    17          0
--       month='Jul'                      0          1
--       month='Aug'                      4          4
--       month='May'                      1          1
--   duplicate employee-months            1          0
--   unique period index                 no        yes
--   hrms_emp_leaves                     41         41   (untouched)
--   hrms_leave_approval_steps           41         41   (untouched)
--   tenant_setting                       0          0   (no listed tenant here)
--   user_onboarding_status           absent    created
--   payroll.payslip.superseded           0          1   (carries all 16 before-images)
--
-- Re-check any line above with:
--   php artisan tinker --execute='...DB::connection("live")...'
-- =====================================================================
