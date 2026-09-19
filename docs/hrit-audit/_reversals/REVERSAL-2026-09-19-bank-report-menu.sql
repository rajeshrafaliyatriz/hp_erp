-- REVERSAL for 2026_09_19_100000_add_payroll_bank_report_menu.php
--
-- Applies to BOTH hosts:
--   mysql  202.47.117.220  (web.triz.co.in,  MariaDB 10.11.9) - the app's DB
--   live   128.199.17.97   (lms.triz.co.in,  MariaDB 10.1.48)
--
-- WHAT THE MIGRATION DID
--   1. Renamed menu 140 from "Monthly Payroll Report" to "Monthly Payroll".
--      access_link was deliberately NOT touched - it is the key the frontend
--      content map matches on.
--   2. Inserted menu 307 "Bank-wise Payment Advice" under parent 95.
--   3. Inserted one tblgroupwise_rights_g2g row (can_view = 1) for every
--      profile whose role_key satisfies admin or hr. Profiles outside that set
--      got NO row at all, because canView() treats an absent row as no access.
--
-- BEFORE-IMAGE, captured 2026-09-19 on both hosts:
--   tblmenumaster_g2g id 307  : DID NOT EXIST (max id was 306 on both)
--   tblmenumaster_g2g id 140  : menu_name = 'Monthly Payroll Report'
--                               parent_id = 95, level = 3, status = 1,
--                               sort_order = 9,
--                               access_link = '/module/hrit-solutions/payroll-management/monthly-payroll-report'
--   tblgroupwise_rights_g2g where menu_id = 307 : ZERO rows
--
-- Nothing else was read or written. No employee, payroll or leave row is
-- touched by the migration or by this reversal.
--
-- TO REVERSE
--   php artisan migrate:rollback --step=1            (preferred - same logic)
-- or run this file directly against each host:

-- 1. Remove the rights rows first; they reference the menu.
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id = 307;

-- 2. Remove the menu row.
DELETE FROM tblmenumaster_g2g WHERE id = 307;

-- 3. Restore the label on menu 140. Guarded on the current value so this is
--    safe to run twice, and so it cannot clobber a different rename made later.
UPDATE tblmenumaster_g2g
   SET menu_name = 'Monthly Payroll Report',
       updated_at = NOW()
 WHERE id = 140
   AND menu_name = 'Monthly Payroll';

-- VERIFY (expect: 0, 0, and 'Monthly Payroll Report')
-- SELECT COUNT(*) FROM tblmenumaster_g2g       WHERE id = 307;
-- SELECT COUNT(*) FROM tblgroupwise_rights_g2g WHERE menu_id = 307;
-- SELECT menu_name FROM tblmenumaster_g2g      WHERE id = 140;
