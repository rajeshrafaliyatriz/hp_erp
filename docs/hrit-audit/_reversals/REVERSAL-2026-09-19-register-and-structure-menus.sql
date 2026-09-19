-- REVERSAL for 2026_09_19_130000_add_payroll_register_and_structure_report_menus.php
--
-- Applies to BOTH hosts:
--   mysql  202.47.117.220  (web.triz.co.in,  MariaDB 10.11.9) - the app's DB
--   live   128.199.17.97   (lms.triz.co.in,  MariaDB 10.1.48)
--
-- WHAT THE MIGRATION DID
--   1. Inserted menu 310 "Payroll Register" and menu 311 "Salary Structure
--      Report" under parent 95 (Payroll Management), status 1, sort_order
--      12 and 13.
--   2. Inserted one tblgroupwise_rights_g2g row per menu (can_view = 1, every
--      other permission 0) for each profile whose role_key satisfies admin or
--      hr. Profiles outside that set got NO row - absence is how this system
--      expresses "no access", so there is nothing to un-set for them.
--
--      Restricted, not universal, because both reports show EVERY employee's
--      pay. Menus 305 (My HR) and 309 (Monthly Attendance Report) are granted
--      to every profile instead, because those show the viewer only themselves
--      and the server enforces it.
--
-- BEFORE-IMAGE, captured 2026-09-19 on both hosts:
--   tblmenumaster_g2g id 310                     : DID NOT EXIST (max id 309)
--   tblmenumaster_g2g id 311                     : DID NOT EXIST
--   tblgroupwise_rights_g2g where menu_id = 310  : ZERO rows
--   tblgroupwise_rights_g2g where menu_id = 311  : ZERO rows
--
-- No employee, payroll, attendance or leave row is touched by the migration or
-- by this reversal. Menus 307, 308 and 309 are SEPARATE migrations with their
-- own reversal files; this one does not affect them.
--
-- TO REVERSE
--   php artisan migrate:rollback --step=1            (preferred - same logic)
-- or run this file directly against each host:

-- Rights first; they reference the menus.
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id IN (310, 311);

DELETE FROM tblmenumaster_g2g WHERE id IN (310, 311);

-- VERIFY (expect 0 and 0)
-- SELECT COUNT(*) FROM tblmenumaster_g2g       WHERE id IN (310, 311);
-- SELECT COUNT(*) FROM tblgroupwise_rights_g2g WHERE menu_id IN (310, 311);
