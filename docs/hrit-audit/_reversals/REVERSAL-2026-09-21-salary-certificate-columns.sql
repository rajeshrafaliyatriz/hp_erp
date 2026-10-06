-- REVERSAL - 2026-09-21 - hrms_salary_certificate column widths (F-212)
--
-- Reverses database/migrations/2026_09_21_130000_widen_salary_certificate_selection_columns.php
--
-- Prefer:  php artisan migrate:rollback --step=1                  (202.47.117.220)
--          php artisan migrate:rollback --database=live --step=1  (128.199.17.97)
--
-- The migration's own down() runs the guard below for you and REFUSES rather
-- than truncating. This file is for when artisan is not available; run the
-- check yourself, because MySQL will not.
--
-- BEFORE (both hosts, verified via SHOW COLUMNS on 2026-09-21):
--   month            varchar(20)   NULL  default NULL
--   payroll_type_id  varchar(50)   NULL  default NULL
--
-- AFTER the migration:
--   month            varchar(64)   NULL
--   payroll_type_id  varchar(255)  NULL

-- 1. THE GUARD. Narrowing is only safe while nothing stored is longer than the
--    old limit - and the whole point of the migration was to allow values that
--    are. Expected: 0. Anything else means rolling back would silently destroy
--    part of a salary certificate's record of which months it covers.
SELECT COUNT(*) AS rows_that_would_be_truncated
  FROM hrms_salary_certificate
 WHERE CHAR_LENGTH(month) > 20
    OR CHAR_LENGTH(payroll_type_id) > 50;

-- 2. Only if the count above is 0:
ALTER TABLE hrms_salary_certificate MODIFY month           VARCHAR(20)  NULL;
ALTER TABLE hrms_salary_certificate MODIFY payroll_type_id VARCHAR(50)  NULL;

-- 3. Confirm.
SHOW COLUMNS FROM hrms_salary_certificate WHERE Field IN ('month', 'payroll_type_id');
