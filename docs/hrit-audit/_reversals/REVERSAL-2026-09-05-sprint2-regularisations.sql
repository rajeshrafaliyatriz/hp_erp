-- Reversal for HRIT Sprint 2's regularisation table
-- (2026_09_05_140000_create_hrms_attendance_regularisations_table).
-- Host: 202.47.117.220/hp_erp. Equivalent to `php artisan migrate:rollback`.
-- The table is new in this sprint, so dropping it loses nothing that predates it.
DROP TABLE IF EXISTS hrms_attendance_regularisations;
