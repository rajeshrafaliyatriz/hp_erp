-- REVERSAL - 2026-10-09 - employee schedule requests (header + days)
--
-- Reverses, in this order (detail first, then header):
--   database/migrations/2026_10_09_100100_create_hrms_employee_schedule_request_days_table.php
--   database/migrations/2026_10_09_100000_create_hrms_employee_schedule_requests_table.php
--
-- Prefer artisan, which does both in the right order:
--   php artisan migrate:rollback --path=database/migrations/2026_10_09_100100_create_hrms_employee_schedule_request_days_table.php
--   php artisan migrate:rollback --path=database/migrations/2026_10_09_100000_create_hrms_employee_schedule_requests_table.php
--   ... and the same two with --database=live
--
-- Applied to BOTH hosts on 2026-10-09:
--   202.47.117.220 (web.triz.co.in)   MariaDB 10.11.9
--   128.199.17.97  (lms.triz.co.in)   MariaDB 10.1.48
--
-- BEFORE: neither table existed on either host.
-- AFTER:  both created, empty.

-- ---------------------------------------------------------------------------
-- DROPPING THESE DOES NOT UNDO AN APPROVAL
-- ---------------------------------------------------------------------------
-- These tables hold what employees ASKED FOR and what was decided. An approval
-- writes the employee's `tbluser` weekday columns - and THAT is the live state
-- that attendance and payroll read. Dropping these tables removes the record of
-- who asked and who agreed, and changes nobody's hours.
--
-- So this is the wrong tool for undoing an applied request. To undo the HOURS:
--   * the before-image of each weekday is in hrms_employee_schedule_request_days
--     (`current_is_working`, `current_in_time`, `current_out_time`) - captured at
--     SUBMISSION, which is the only place it exists;
--   * the approval also emits a g2g_event, which carries the same before-image.
-- Read one of those FIRST. Once this table is gone, the only record of what an
-- employee's hours used to be is the event log.

SELECT COUNT(*) AS requests,
       SUM(status = 'pending')   AS pending,
       SUM(status = 'approved')  AS approved,
       SUM(status = 'rejected')  AS rejected,
       SUM(status = 'cancelled') AS cancelled,
       SUM(deleted_at IS NOT NULL) AS withdrawn_soft_deleted
  FROM hrms_employee_schedule_requests;

-- Anything PENDING is somebody waiting on an answer. Dropping the table makes
-- their request vanish from the approver's queue with no notification.
SELECT r.id, r.sub_institute_id, r.user_id,
       CONCAT_WS(' ', u.first_name, u.last_name) AS employee,
       r.status, r.created_at, r.reason
  FROM hrms_employee_schedule_requests r
  LEFT JOIN tbluser u ON u.id = r.user_id
 WHERE r.status = 'pending' AND r.deleted_at IS NULL
 ORDER BY r.created_at;

-- The approved ones are the rows whose hours are now live on tbluser:
SELECT r.id, r.user_id, r.applied_at,
       GROUP_CONCAT(CONCAT(d.weekday, IF(d.is_working, '', ' (off)')) ORDER BY d.weekday) AS week_applied
  FROM hrms_employee_schedule_requests r
  JOIN hrms_employee_schedule_request_days d ON d.request_id = r.id
 WHERE r.status = 'approved'
 GROUP BY r.id, r.user_id, r.applied_at;

-- ---------------------------------------------------------------------------
-- Only if the counts above are 0, or you have exported them.
-- Detail first: the days table references request_id.
-- ---------------------------------------------------------------------------
-- DROP TABLE hrms_employee_schedule_request_days;
-- DROP TABLE hrms_employee_schedule_requests;

-- Confirm.
-- SHOW TABLES LIKE 'hrms_employee_schedule_request%';

-- ---------------------------------------------------------------------------
-- ALSO CONSIDER
-- ---------------------------------------------------------------------------
-- Roster provenance rows written by approvals from this table are in
-- hrms_employee_roster_provenance with source = 'employee_request' and
-- source_ref_id = the request id. Dropping these tables leaves those rows
-- pointing at nothing - harmless to the apply (it only reads `source`), but the
-- preview's "set in request #412" detail becomes unresolvable. See
-- REVERSAL-2026-10-09-roster-provenance.sql.
