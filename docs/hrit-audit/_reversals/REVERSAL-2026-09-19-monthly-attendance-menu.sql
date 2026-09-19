-- REVERSAL for 2026_09_19_120000_add_monthly_attendance_report_menu.php
--
-- Applies to BOTH hosts:
--   mysql  202.47.117.220  (web.triz.co.in,  MariaDB 10.11.9) - the app's DB
--   live   128.199.17.97   (lms.triz.co.in,  MariaDB 10.1.48)
--
-- WHAT THE MIGRATION DID
--   1. Inserted menu 309 "Monthly Attendance Report" under parent 93
--      (Attendance Management), status 1, sort_order 3.
--   2. Inserted one tblgroupwise_rights_g2g row (can_view = 1, every other
--      permission 0) for EVERY profile.
--
--      That is intentional and different from menus 307/308, which are
--      restricted to admin/hr. This endpoint enforces HR-or-self in the
--      controller (F-159): a non-HR profile reading anyone but themselves gets
--      403. So a rights row grants an employee their OWN month only.
--
-- BEFORE-IMAGE, captured 2026-09-19 on both hosts:
--   tblmenumaster_g2g id 309                     : DID NOT EXIST (max id 308)
--   tblgroupwise_rights_g2g where menu_id = 309  : ZERO rows
--
-- No attendance, employee, payroll or leave row is touched by the migration or
-- by this reversal.
--
-- TO REVERSE
--   php artisan migrate:rollback --step=1            (preferred - same logic)
-- or run this file directly against each host:

-- Rights first; they reference the menu.
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id = 309;

DELETE FROM tblmenumaster_g2g WHERE id = 309;

-- VERIFY (expect 0 and 0)
-- SELECT COUNT(*) FROM tblmenumaster_g2g       WHERE id = 309;
-- SELECT COUNT(*) FROM tblgroupwise_rights_g2g WHERE menu_id = 309;
