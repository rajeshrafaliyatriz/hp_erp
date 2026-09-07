-- Reversal for Sprint 6 (F-124). Applied to 202.47.117.220/hp_erp on 2026-09-05.
--
-- WHAT WAS DONE
--   2026_09_05_190000  created hrms_leave_approval_steps
--   2026_09_05_190100  backfilled one chain per live row in hrms_emp_leaves
--   2026_09_05_200000  added `decision`, and corrected two things the backfill
--                      got wrong (see below)
--
-- Nothing in hrms_emp_leaves, hrms_leave_workflow_settings or any other
-- pre-existing table was altered. This change is PURELY ADDITIVE at the schema
-- level: a new table, and rows in it. That is why this script is short and why
-- the rollback is genuinely complete, unlike Sprint 5's.
--
-- HOW TO REVERSE
--
--   php artisan migrate:rollback --step=3
--
-- which runs both down() methods and drops the table. Use the SQL below only if
-- the migration files are unavailable.

-- 1. Drop the table. The foreign key on leave_id goes with it; nothing else
--    references hrms_leave_approval_steps.
DROP TABLE IF EXISTS `hrms_leave_approval_steps`;

-- 2. Remove the migration records so a later `migrate` does not think they ran.
DELETE FROM `migrations`
 WHERE `migration` IN (
   '2026_09_05_190000_create_hrms_leave_approval_steps_table',
   '2026_09_05_190100_backfill_leave_approval_steps',
   '2026_09_05_200000_repair_leave_approval_steps'
 );

-- ---------------------------------------------------------------------------
-- WHAT REVERSING COSTS YOU
--
-- The behaviour, not the data. With the table gone,
-- LeaveRequestApiController::decision() finds no steps for any request and
-- falls through its "no chain" branch - one approval from anyone holding
-- approve_leave decides the request again, whatever the tenant configured.
-- That is the F-124 defect, restored.
--
-- Any approval that was recorded as a chain step (who approved which stage,
-- when, with what comment) is destroyed by the DROP and is not recoverable.
-- hrms_emp_leaves.status, .approved_by, .hod_comment and .hr_remarks are
-- untouched by this change and survive, so the request's final outcome and its
-- last approver remain. It is the intermediate approvals that are lost.
--
-- Take a copy first if that history matters:
--   CREATE TABLE hrms_leave_approval_steps_backup
--   AS SELECT * FROM hrms_leave_approval_steps;
--
-- ---------------------------------------------------------------------------
-- ONE THING THE THIRD MIGRATION DOES NOT PUT BACK, AND SHOULD NOT
--
-- 2026_09_05_200000 corrected two things the backfill got wrong:
--
--   * steps 2..n of an already-approved request were marked 'approved'. Only
--     ONE decision was ever made under the old single-decision rule, so those
--     steps now read 'skipped' with no attribution.
--   * a 'cancelled' request had been recorded as a REJECTION attributed to the
--     approver. It very often was not the approver who cancelled it.
--
-- Its down() drops the `decision` column and STOPS THERE. Reversing the status
-- corrections would mean re-asserting that approvals happened which did not,
-- and a rollback is not a licence to restore a falsehood. That is a deliberate
-- asymmetry, stated here so nobody re-derives it as an oversight.
--
-- ---------------------------------------------------------------------------
-- ALSO IN SPRINT 6, AND NOT REVERSED HERE
--
-- The payroll fixes (F-109, F-125) are CODE ONLY - no migration, no schema
-- change. Reverting them is `git revert` on
-- app/Http/Controllers/Payroll/PayrollController.php, and doing so reinstates
-- both defects: saving a month twice duplicates every payslip again, and
-- saving payroll for an employee with no salary structure fatals after the
-- row has been written.
--
-- Note that the upsert has already had an effect on live data that this file
-- cannot undo: from the moment it deployed, re-saving a month collapses that
-- employee-month's duplicate rows down to one. Tenant 1 held 17 rows for
-- employee 1 / july 2026 before the fix. Those rows are only removed when that
-- month is saved again, and the collapse keeps the EARLIEST row (so the
-- original created_at survives) with the newest figures.
