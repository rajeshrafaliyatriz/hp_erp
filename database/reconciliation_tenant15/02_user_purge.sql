-- =============================================================================
-- STEP 2: EMPLOYEE SCOPE REDUCTION & PRE-PURGE BACKUP (R1)
-- Database: hp_erp (MariaDB 10.1 on db3 / 128.199.17.97)
-- Tenant: Future Varsity (sub_institute_id = 15)
-- Author: Teamwork Worker 1
-- Date: 2026-11-04
-- =============================================================================

-- 1. Create backup table if not exists (matching schema of tbluser)
CREATE TABLE IF NOT EXISTS `tbluser_backup_tenant15_purge_20261104` LIKE `tbluser`;

START TRANSACTION;

-- 2. Back up the 145 provisional users from "All Verticals" batch
INSERT INTO `tbluser_backup_tenant15_purge_20261104`
SELECT * FROM `tbluser`
WHERE `sub_institute_id` = 15
  AND `id` != 479
  AND `id` NOT BETWEEN 564 AND 579;

-- 3. Delete the 145 provisional users from tbluser
DELETE FROM `tbluser`
WHERE `sub_institute_id` = 15
  AND `id` != 479
  AND `id` NOT BETWEEN 564 AND 579;

COMMIT;
