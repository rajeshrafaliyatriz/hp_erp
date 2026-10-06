-- Reversal for HRIT Sprint 2's work_mode column
-- (2026_09_05_150000_add_work_mode_to_hrms_attendances_table).
-- Host: 202.47.117.220/hp_erp. Equivalent to `php artisan migrate:rollback`.
--
-- Dropping it loses only the work mode captured since the column was added; the
-- 994 rows that predate it were backfilled with the default 'office', which is
-- what the UI displayed for them before the column existed.
ALTER TABLE hrms_attendances DROP COLUMN work_mode;
