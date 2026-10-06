-- Reversal for HRIT Sprint 4's chargeable_days column
-- (2026_09_05_160000_add_chargeable_days_to_hrms_emp_leaves_table).
-- Host: 202.47.117.220/hp_erp. Equivalent to `php artisan migrate:rollback`.
--
-- Dropping it returns leave-day counting to the calendar-day arithmetic the
-- audit filed as F-95, so only roll this back if that is what you intend.
ALTER TABLE hrms_emp_leaves DROP COLUMN chargeable_days;
