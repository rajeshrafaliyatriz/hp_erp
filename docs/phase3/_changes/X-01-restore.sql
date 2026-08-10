-- X-01 ROLLBACK. Restores `tblgroupwise_rights_g2g` to its pre-population state.
-- 4879 rows, taken 2026-08-10.
--
-- Usage:
--   mysql <db> < X-01-restore.sql
--   then source the backup file named below.
--
START TRANSACTION;
DELETE FROM `tblgroupwise_rights_g2g`;
-- now source: X-01-backup-tblgroupwise_rights_g2g-2026-08-10.sql
COMMIT;
