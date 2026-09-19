-- REVERSAL for 2026_09_19_110000_add_payroll_history_menu.php
--
-- Applies to BOTH hosts:
--   mysql  202.47.117.220  (web.triz.co.in,  MariaDB 10.11.9) - the app's DB
--   live   128.199.17.97   (lms.triz.co.in,  MariaDB 10.1.48)
--
-- WHAT THE MIGRATION DID
--   1. Inserted menu 308 "Employee Payroll History" under parent 95
--      (Payroll Management), status 1, sort_order 11.
--   2. Inserted one tblgroupwise_rights_g2g row (can_view = 1, every other
--      permission 0) for each profile whose role_key satisfies admin or hr.
--      Profiles outside that set got NO row - absence is how this system
--      expresses "no access".
--
-- BEFORE-IMAGE, captured 2026-09-19 on both hosts:
--   tblmenumaster_g2g id 308                     : DID NOT EXIST (max id 307)
--   tblgroupwise_rights_g2g where menu_id = 308  : ZERO rows
--
-- No employee, payroll or leave row is touched by the migration or by this
-- reversal. Menu 307 (Bank-wise Payment Advice) is a SEPARATE migration with
-- its own reversal file; this one does not affect it.
--
-- TO REVERSE
--   php artisan migrate:rollback --step=1            (preferred - same logic)
-- or run this file directly against each host:

-- Rights first; they reference the menu.
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id = 308;

DELETE FROM tblmenumaster_g2g WHERE id = 308;

-- VERIFY (expect 0 and 0)
-- SELECT COUNT(*) FROM tblmenumaster_g2g       WHERE id = 308;
-- SELECT COUNT(*) FROM tblgroupwise_rights_g2g WHERE menu_id = 308;
