-- REVERSAL for Q8: the two created salary structures, and the payslip recompute.
--
-- APPLIES TO BOTH HOSTS. This was checked, not assumed: employee_monthly_salary_data
-- and employee_salary_structures were read on each and are BYTE-IDENTICAL -
-- 6 payslips and 8 structures, same ids, same values, on
--   mysql  202.47.117.220  (web.triz.co.in)   and
--   live   128.199.17.97   (lms.triz.co.in).
-- A change applied to one host only would make them diverge, so run this file
-- against BOTH, exactly as the change was.
--
-- FULL BEFORE-IMAGE: Docs/hrit-audit/_evidence/before-q8.json, captured
-- 2026-09-19 immediately before the change. The statements below are GENERATED
-- from that file rather than transcribed - an earlier hand-written version of
-- this reversal had every JSON payload wrong.
--
-- WHY THIS NEEDED A DECISION
--   The remediation plan said all six payslips would move. Running the actual
--   calculation (getEmpMonthlyData) showed otherwise:
--     payslips 2, 3, 4   stored value ALREADY equals what it produces
--     payslip  1         25,000 -> 2,823
--     payslips 5, 22     REFUSED - "Salary Structure Not Found !!"
--   The plan-s "new pay" column was the sum of each payslip-s stored
--   components, not the output of a recompute.
--   Evidence: Docs/hrit-audit/_evidence/q8-recompute-preview.php (read-only).
--
--   Shown that, the customer chose to create the two missing structures and
--   then recompute. The structures are NOT invented: each is built from its own
--   payslip-s stored employee_salary_data, verbatim, because that is what the
--   payslip was actually paid against.
--
-- ============================================================
-- 1. Remove the two created salary structures
-- ============================================================
-- Neither existed before (verified by query on both hosts, 2026-09-19).
-- Deleted by natural key rather than id, because the two hosts assign their own.

DELETE FROM employee_salary_structures
 WHERE employee_id = 1 AND year = 2026 AND sub_institute_id = 1;

DELETE FROM employee_salary_structures
 WHERE employee_id = 6 AND year = 2025 AND sub_institute_id = 3;

-- ============================================================
-- 2. Restore the payslips, verbatim
-- ============================================================
UPDATE employee_monthly_salary_data SET
    total_payment        = 25000.00,
    total_deduction      = 100.00,
    total_day            = 10.00,
    employee_salary_data = '{"6":"15000"}'
 WHERE id = 1;

UPDATE employee_monthly_salary_data SET
    total_payment        = 3306.00,
    total_deduction      = 0.00,
    total_day            = 25.00,
    employee_salary_data = '{"6":"1000"}'
 WHERE id = 2;

UPDATE employee_monthly_salary_data SET
    total_payment        = 3403.00,
    total_deduction      = 0.00,
    total_day            = 28.00,
    employee_salary_data = '{"6":"1000"}'
 WHERE id = 3;

UPDATE employee_monthly_salary_data SET
    total_payment        = 3500.00,
    total_deduction      = 0.00,
    total_day            = 31.00,
    employee_salary_data = '{"6":"1000"}'
 WHERE id = 4;

UPDATE employee_monthly_salary_data SET
    total_payment        = 35000.00,
    total_deduction      = 100.00,
    total_day            = 10.00,
    employee_salary_data = '{"6":"7000"}'
 WHERE id = 5;

UPDATE employee_monthly_salary_data SET
    total_payment        = 81300.00,
    total_deduction      = 6200.00,
    total_day            = 30.00,
    employee_salary_data = '{"1":50000,"2":5000,"3":20000,"4":10000,"5":2500,"6":0,"7":0,"8":0,"9":6000,"10":200,"11":5}'
 WHERE id = 22;

-- ============================================================
-- 3. The event trail is NOT reversed, deliberately
-- ============================================================
-- Each rewritten payslip emitted `payroll.payslip.superseded` carrying its own
-- before-image. g2g_event is append-only by design (EventRecorder: "no UPDATE,
-- no DELETE"), and deleting the record of a correction would defeat the reason
-- it was recorded. The events stay; they describe a change that was undone.

-- VERIFY after running, on BOTH hosts:
-- SELECT id, month, year, total_day, total_deduction, total_payment
--   FROM employee_monthly_salary_data ORDER BY id;
-- SELECT COUNT(*) FROM employee_salary_structures;   -- expect 8
