-- REVERSAL - 2026-10-07 - hrms_department_schedules
--
-- Reverses database/migrations/2026_10_07_120000_create_hrms_department_schedules_table.php
--
-- Prefer:
--   php artisan migrate:rollback --path=database/migrations/2026_10_07_120000_create_hrms_department_schedules_table.php
--   php artisan migrate:rollback --database=live --path=...
--
-- Applied to BOTH hosts on 2026-10-07:
--   202.47.117.220 (web.triz.co.in)   MariaDB 10.11.9
--   128.199.17.97  (lms.triz.co.in)   MariaDB 10.1.48
--
-- BEFORE: the table did not exist on either host.
-- AFTER:  created, empty.

-- ---------------------------------------------------------------------------
-- DROPPING THIS DOES NOT UNDO AN APPLY
-- ---------------------------------------------------------------------------
-- This table is a TEMPLATE. Pressing "Apply to department" copies it onto the
-- tbluser columns - the seven weekday flags and the fourteen time columns -
-- because those are what the attendance and payroll calculations read.
--
-- So dropping this table removes the template and leaves every employee's
-- roster exactly as the last apply left it. That is usually what you want. If
-- you need to undo an APPLY, this file is the wrong tool: the employee rosters
-- are in tbluser, the apply is recorded as a `department.schedule.applied`
-- event in g2g_event with the before-image of every employee it touched, and
-- that event is what an undo has to be built from.
--
-- Check what you are about to lose:

SELECT COUNT(*) AS schedules FROM hrms_department_schedules;

SELECT sub_institute_id,
       COUNT(DISTINCT department_id) AS departments,
       COUNT(*)                      AS weekday_rows,
       MAX(updated_at)               AS last_change
  FROM hrms_department_schedules
 GROUP BY sub_institute_id;

-- The departments that would lose their template, by name:
SELECT s.sub_institute_id, s.department_id, d.department,
       GROUP_CONCAT(CONCAT(s.weekday, IF(s.is_working, '', ' (off)')) ORDER BY s.weekday) AS week
  FROM hrms_department_schedules s
  LEFT JOIN hrms_departments d ON d.id = s.department_id
 GROUP BY s.sub_institute_id, s.department_id, d.department;

-- ---------------------------------------------------------------------------
-- Only if the count above is 0, or you have exported it:
-- ---------------------------------------------------------------------------
-- DROP TABLE hrms_department_schedules;

-- Confirm.
-- SHOW TABLES LIKE 'hrms_department_schedules';
