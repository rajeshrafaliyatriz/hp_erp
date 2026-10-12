-- REVERSAL - 2026-10-09 - hrms_attendance_edits.regularisation_id
--
-- Reverses database/migrations/2026_10_09_100300_add_regularisation_id_to_hrms_attendance_edits_table.php
--
-- Prefer artisan, which drops the index before the column:
--   php artisan migrate:rollback --path=database/migrations/2026_10_09_100300_add_regularisation_id_to_hrms_attendance_edits_table.php
--   php artisan migrate:rollback --database=live --path=...
--
-- Applied to BOTH hosts on 2026-10-09:
--   202.47.117.220 (web.triz.co.in)   MariaDB 10.11.9
--   128.199.17.97  (lms.triz.co.in)   MariaDB 10.1.48
--
-- BEFORE: the column did not exist on either host.
-- AFTER:  nullable BIGINT UNSIGNED after `attendance_id`, plus the index
--         `hae_regularisation_idx`. No rows populated - nothing writes it until
--         the approval path's change ships.
--
-- NOTE ON THE MIGRATION'S SHAPE: it uses raw ALTER statements and an
-- information_schema probe rather than Schema::hasColumn(), because `live` is
-- MariaDB 10.1 and Laravel's column introspection SELECTs
-- `generation_expression`, which 10.1's information_schema.COLUMNS does not
-- have. Schema::hasTable() is safe there; column checks throw.

-- ---------------------------------------------------------------------------
-- WHAT YOU WOULD LOSE
-- ---------------------------------------------------------------------------
-- This column is the only link from an audit row back to the employee request
-- that caused it. Dropping it does not delete any edit row - the before-image,
-- the after-image, the reason and the actor all survive - but an edit with
-- `source = 'regularisation'` becomes unattributable to a specific request, and
-- the only remaining link is `g2g_event` (event_type `attendance.corrected`,
-- regularisation id inside the JSON payload string).
--
-- Check what is populated before dropping:

SELECT COUNT(*)                        AS edits_total,
       SUM(regularisation_id IS NOT NULL) AS from_a_request,
       SUM(source = 'regularisation')     AS flagged_regularisation
  FROM hrms_attendance_edits;

-- Those last two should agree. If `flagged_regularisation` is larger, some rows
-- were written with the source but no id - worth understanding before dropping
-- the column that would have explained them.

SELECT sub_institute_id, source, COUNT(*) AS rows_
  FROM hrms_attendance_edits
 GROUP BY sub_institute_id, source
 ORDER BY sub_institute_id, source;

-- ---------------------------------------------------------------------------
-- The reversal. Index first - dropping a column with an index on it works, but
-- leaves the index name free in a way that confuses a re-run of the migration.
-- ---------------------------------------------------------------------------
-- ALTER TABLE hrms_attendance_edits DROP INDEX hae_regularisation_idx;
-- ALTER TABLE hrms_attendance_edits DROP COLUMN regularisation_id;

-- ---------------------------------------------------------------------------
-- Confirm. Both should return no rows.
-- ---------------------------------------------------------------------------
-- SELECT COLUMN_NAME FROM information_schema.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hrms_attendance_edits'
--    AND COLUMN_NAME = 'regularisation_id';
-- SELECT INDEX_NAME FROM information_schema.STATISTICS
--  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hrms_attendance_edits'
--    AND INDEX_NAME = 'hae_regularisation_idx';

-- ---------------------------------------------------------------------------
-- ORDERING, IF YOU ARE REVERSING THE WHOLE CHANGE
-- ---------------------------------------------------------------------------
-- Roll back the CONTROLLER change before this migration. The approval path
-- writes `regularisation_id` on every approval; dropping the column while that
-- code is live makes every attendance-regularisation approval fail with an
-- unknown-column error - on a path employees use, not an admin one.
