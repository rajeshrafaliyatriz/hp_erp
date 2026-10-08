-- REVERSAL - 2026-10-07 - hrms_attendance_edits
--
-- Reverses database/migrations/2026_10_07_100000_create_hrms_attendance_edits_table.php
--
-- Prefer:  php artisan migrate:rollback --path=database/migrations/2026_10_07_100000_create_hrms_attendance_edits_table.php
--          php artisan migrate:rollback --database=live --path=...
--
-- Applied to BOTH hosts on 2026-10-07:
--   202.47.117.220 (web.triz.co.in)
--   128.199.17.97  (lms.triz.co.in)
--
-- BEFORE: the table did not exist on either host.
-- AFTER:  created, empty.
--
-- Note the migration was run with --path on purpose. Both hosts carry pending
-- migrations belonging to other work (AI tables, document master, role_key
-- backfills); a bare `php artisan migrate` would have run those too.

-- ---------------------------------------------------------------------------
-- READ THIS BEFORE DROPPING
-- ---------------------------------------------------------------------------
-- This table is the HR-readable record of who changed whose attendance, and
-- attendance feeds pay: timestamp_diff is read by PayrollController, and a
-- corrected 2nd-Saturday punch changes the late count subtracted from payable
-- days. Dropping it destroys the only per-employee-per-day account of those
-- changes.
--
-- The platform event log (g2g_event, event_type 'attendance.corrected') still
-- holds a before-image of every correction, so the FACTS survive a drop - but
-- they survive in a form nobody on the HR desk will query. Check what you are
-- about to lose first:

SELECT COUNT(*) AS edits_recorded FROM hrms_attendance_edits;

SELECT sub_institute_id, COUNT(*) AS edits, MIN(created_at) AS first, MAX(created_at) AS last
  FROM hrms_attendance_edits
 GROUP BY sub_institute_id;

-- ---------------------------------------------------------------------------
-- Only if the count above is 0, or you have exported it:
-- ---------------------------------------------------------------------------
-- DROP TABLE hrms_attendance_edits;

-- Confirm.
-- SHOW TABLES LIKE 'hrms_attendance_edits';
