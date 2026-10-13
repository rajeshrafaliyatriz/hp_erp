-- REVERSAL - 2026-10-07 - "Manage Employee Attendance" menu 433 + its rights
--
-- Reverses, in this order (rights first, then the menu row):
--   database/migrations/2026_10_07_110100_grant_manage_employee_attendance_rights.php
--   database/migrations/2026_10_07_110000_add_manage_employee_attendance_menu.php
--
-- Prefer artisan, which does both and in the right order:
--   php artisan migrate:rollback --path=database/migrations/2026_10_07_110100_grant_manage_employee_attendance_rights.php
--   php artisan migrate:rollback --path=database/migrations/2026_10_07_110000_add_manage_employee_attendance_menu.php
--   ... and the same two with --database=live
--
-- Applied to BOTH hosts on 2026-10-07:
--   202.47.117.220 (web.triz.co.in)   MariaDB 10.11.9
--   128.199.17.97  (lms.triz.co.in)   MariaDB 10.1.48
--
-- BEFORE: id 433 free on both; no row with this access_link on either.
-- AFTER:  one menu row, plus one rights row per (admin/hr profile x tenant x
--         ancestor) that did not already have one.
--
-- WHY 433 AND NOT 312: 312 is free on 202.47.117.220 and TAKEN by "Platform
-- Services" on 128.199.17.97. The migration guards on the id, so 312 would have
-- inserted on one host and silently skipped on the other.

-- ---------------------------------------------------------------------------
-- WHAT YOU ARE ABOUT TO REMOVE
-- ---------------------------------------------------------------------------
SELECT id, menu_name, parent_id, status, sort_order, access_link
  FROM tblmenumaster_g2g
 WHERE id = 433
    OR access_link = '/module/hrit-solutions/attendance-management/manage-employee-attendance';

SELECT r.sub_institute_id, COUNT(*) AS grants
  FROM tblgroupwise_rights_g2g r
 WHERE r.menu_id = 433
 GROUP BY r.sub_institute_id;

-- The ancestors (HRIT Solutions -> Attendance Management) are NOT listed here
-- and must NOT be revoked: their rows are shared with Attendance Tracking,
-- Attendance Reports and Monthly Attendance Report. Revoking a shared ancestor
-- blanks those screens out of the sidebar too (F-209, in the other direction).

-- ---------------------------------------------------------------------------
-- The reversal. Rights first - a menu row with orphaned grants is harmless,
-- an orphaned grant pointing at a deleted menu id is what confuses the
-- Role & Permissions screen.
-- ---------------------------------------------------------------------------
-- DELETE FROM tblgroupwise_rights_g2g WHERE menu_id = 433;
--
-- DELETE FROM tblmenumaster_g2g
--  WHERE id = 433
--    AND access_link = '/module/hrit-solutions/attendance-management/manage-employee-attendance';

-- ---------------------------------------------------------------------------
-- Confirm. Both should return no rows.
-- ---------------------------------------------------------------------------
-- SELECT id, menu_name FROM tblmenumaster_g2g WHERE id = 433;
-- SELECT COUNT(*) FROM tblgroupwise_rights_g2g WHERE menu_id = 433;

-- The screen itself keeps working for anyone who has the URL after this: the
-- API route is gated by profile:admin,hr independently of the menu, which is
-- the point of gating both. Removing the menu removes the way IN, not the
-- permission. To close the permission as well, the route group in
-- routes/api.php is the place.
