-- ############################################################################
-- ##  READ BEFORE RUNNING. THIS FILE IS THE FLOOR, NOT THE TOOL.            ##
-- ############################################################################
--
-- This restores tblgroupwise_rights_g2g to 2026-08-10. It DELETES 1,002 rows
-- written since, of which 903 ARE NOT THIS ENGAGEMENT'S WORK.
--
-- DO NOT RUN IT TO REVERSE A MENU. Each rights script prints its own per-menu
-- delete, and those are surgical where this is not:
--
--     DELETE FROM tblgroupwise_rights_g2g WHERE menu_id = 227;   (and 228, 229)
--
-- NOTE: those per-menu deletes have never been run either - the same class this
-- backup was in until it was checked.
--
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
