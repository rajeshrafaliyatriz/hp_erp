-- =====================================================================
-- REVERSAL — the My HR menu row (F-130 correction)
-- 2026-09-08
--
-- Migration: database/migrations/2026_09_08_100000_add_my_hr_menu_row.php
-- Applies to BOTH hosts: 202.47.117.220/hp_erp and 128.199.17.97/hp_erp.
--
-- What it did, and why it needed two inserts:
--
--   My HR (/api/my-hr/*, the only screen where an employee can see their own
--   payslip) was built in Sprint 8 and had NO row in tblmenumaster_g2g, so it
--   was unreachable by navigation. F-130 was recorded as CLOSED.
--
--   A menu row alone would not have fixed it. canView() reads
--   ($rights->can_view ?? 0) == 1, so a menu with no tblgroupwise_rights_g2g
--   row is invisible - absence is how revocation is expressed in this system.
--   Menu 102 carries 72 such rows for comparison.
--
-- Nothing else was touched. No existing menu, rights row, screen or endpoint
-- was modified; this is purely additive.
-- =====================================================================


-- ---------------------------------------------------------------------
-- PREFERRED: let the migration undo itself, so both tables stay in step.
-- ---------------------------------------------------------------------
--   php artisan migrate:rollback --step=1                    (202.47.117.220)
--   php artisan migrate:rollback --database=live --step=1    (128.199.17.97)


-- ---------------------------------------------------------------------
-- MANUAL, if the migration cannot be run. Rights FIRST - they reference
-- the menu id - then the menu row.
-- ---------------------------------------------------------------------

-- DELETE FROM tblgroupwise_rights_g2g WHERE menu_id = 305;
-- DELETE FROM tblmenumaster_g2g       WHERE id = 305;


-- ---------------------------------------------------------------------
-- VERIFY, before and after
-- ---------------------------------------------------------------------
-- Menu row present?
--   SELECT id, menu_name, parent_id, level, status, sort_order, access_link
--     FROM tblmenumaster_g2g WHERE id = 305;
--
-- How many profiles can see it? (expect: one row per tbluserprofilemaster row)
--   SELECT COUNT(*) FROM tblgroupwise_rights_g2g WHERE menu_id = 305;
--   SELECT COUNT(*) FROM tbluserprofilemaster;
--
-- Nothing else moved?
--   SELECT COUNT(*) FROM tblmenumaster_g2g WHERE access_link LIKE '%hrit%';
--       expect 33 before, 34 after
--
-- The id was chosen because 305 is free on BOTH hosts - each maxes at 304.
-- If this is ever re-applied, re-check that first: a collision would attach
-- My HR's rights rows to somebody else's menu.
-- =====================================================================
