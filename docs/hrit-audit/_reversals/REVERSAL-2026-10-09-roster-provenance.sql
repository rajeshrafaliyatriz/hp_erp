-- REVERSAL - 2026-10-09 - hrms_employee_roster_provenance
--
-- Reverses database/migrations/2026_10_09_100200_create_hrms_employee_roster_provenance_table.php
--
-- Prefer:
--   php artisan migrate:rollback --path=database/migrations/2026_10_09_100200_create_hrms_employee_roster_provenance_table.php
--   php artisan migrate:rollback --database=live --path=...
--
-- Applied to BOTH hosts on 2026-10-09:
--   202.47.117.220 (web.triz.co.in)   MariaDB 10.11.9
--   128.199.17.97  (lms.triz.co.in)   MariaDB 10.1.48
--
-- BEFORE: the table did not exist on either host.
-- AFTER:  created, empty. It fills forward only - see below.

-- ---------------------------------------------------------------------------
-- WHAT DROPPING THIS ACTUALLY CHANGES: "Apply to department" STOPS SKIPPING
-- ---------------------------------------------------------------------------
-- This table records, per employee per weekday, WHERE their hours came from:
-- an employee's own approved request, a department apply, an HR edit through
-- Employee Directory, or an import.
--
-- DepartmentScheduleController's apply reads it to leave alone the employees who
-- set their own hours, and its preview reads it to report them as a separate
-- bucket ("3 employees set their own hours and will be left alone").
--
-- With this table gone, every provenance lookup returns nothing, every employee
-- reads as "not employee-set", and the next "Apply to department" OVERWRITES
-- the hours employees chose - with no skip, no warning and no count. That is
-- exactly the failure the previous version of department shift-setting was
-- removed from the product for.
--
-- It does not change anybody's hours by itself. It removes the protection.
--
-- Check what protection you would be removing:

SELECT COUNT(*) AS rows_, source, COUNT(DISTINCT user_id) AS employees
  FROM hrms_employee_roster_provenance
 GROUP BY source
 ORDER BY source;

-- The employees who would lose their protection, by name:
SELECT p.sub_institute_id,
       p.user_id,
       CONCAT_WS(' ', u.first_name, u.last_name) AS employee,
       d.department,
       GROUP_CONCAT(p.weekday ORDER BY p.weekday) AS weekdays_they_chose,
       MAX(p.set_at) AS last_set
  FROM hrms_employee_roster_provenance p
  LEFT JOIN tbluser u          ON u.id = p.user_id
  LEFT JOIN hrms_departments d ON d.id = u.department_id
 WHERE p.source = 'employee_request'
 GROUP BY p.sub_institute_id, p.user_id, employee, d.department
 ORDER BY p.sub_institute_id, employee;

-- ---------------------------------------------------------------------------
-- NOT RETROACTIVE, IN EITHER DIRECTION
-- ---------------------------------------------------------------------------
-- There is nothing to rebuild this table FROM. Rosters written before it shipped
-- have no provenance and cannot acquire any: absence of a row means "we do not
-- know where this came from", which is honest and true of every roster set
-- before 2026-10-09 and of all 2,008 active employees who have no roster at all.
--
-- So dropping and re-creating it does not restore the previous contents. If you
-- need the employee-chosen rosters back afterwards, the recoverable sources are
-- hrms_employee_schedule_requests (status = 'approved', with applied_at) and the
-- g2g_event rows for those approvals - and re-deriving from them has the flaw
-- the migration's own docblock explains: an approved request followed by a later
-- department apply means the roster is department-set now, and a naive re-derive
-- would mark it employee-set and skip that employee forever.

-- ---------------------------------------------------------------------------
-- Only if the counts above are 0, or you accept losing the skip protection:
-- ---------------------------------------------------------------------------
-- DROP TABLE hrms_employee_roster_provenance;

-- Confirm.
-- SHOW TABLES LIKE 'hrms_employee_roster_provenance';
