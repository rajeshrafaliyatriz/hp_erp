-- REVERSAL - 2026-10-09 - "My Office Hours" menu 434 + its rights
--
-- Reverses, in this order (rights first, then the menu row):
--   database/migrations/2026_10_09_120100_grant_my_office_hours_rights.php
--   database/migrations/2026_10_09_120000_add_my_office_hours_menu.php
--
-- Prefer artisan, which does both and in the right order:
--   php artisan migrate:rollback --path=database/migrations/2026_10_09_120100_grant_my_office_hours_rights.php
--   php artisan migrate:rollback --path=database/migrations/2026_10_09_120000_add_my_office_hours_menu.php
--   ... and the same two with --database=live
--
-- Applies to BOTH hosts:
--   202.47.117.220 (web.triz.co.in)   MariaDB 10.11.9   <- the host the app uses
--   128.199.17.97  (lms.triz.co.in)   MariaDB 10.1.48
--
-- BEFORE (measured on both hosts, 2026-10-09):
--   select max(id) from tblmenumaster_g2g;   -- 433 on BOTH, so 434 was free
--   no row with this access_link on either host
--
-- AFTER:
--   one menu row (id 434, parent 93, level 3, sort_order 6), plus one rights
--   row per non-deleted profile that has a numeric sub_institute_id and did
--   not already hold menu 434.
--
-- WHY THERE ARE NO ANCESTOR RIGHTS ROWS TO REMOVE
--   The grant migration deliberately writes the LEAF ONLY. Ancestors 93 and 5
--   are already granted to Employee on both hosts, and granting them to the
--   profiles that lack them would newly reveal Monthly Attendance Report (309)
--   to ~1,000 Students in tenant 1000000 and ~962 Employees in tenant 1000018.
--   The grant migration's docblock carries the measurement and the queries.
--   So nothing below touches menu 93 or menu 5.

-- ---------------------------------------------------------------------------
-- WHAT YOU ARE ABOUT TO REMOVE
-- ---------------------------------------------------------------------------
SELECT id, menu_name, parent_id, level, status, sort_order, access_link
  FROM tblmenumaster_g2g
 WHERE id = 434
    OR access_link = '/module/hrit-solutions/attendance-management/my-office-hours';

SELECT COUNT(*) AS rights_rows_to_delete
  FROM tblgroupwise_rights_g2g
 WHERE menu_id = (SELECT id FROM tblmenumaster_g2g
                   WHERE access_link = '/module/hrit-solutions/attendance-management/my-office-hours'
                   LIMIT 1);

-- ---------------------------------------------------------------------------
-- THE REVERSAL - rights first, then the menu row
-- ---------------------------------------------------------------------------

-- Rights. Resolved through access_link rather than the literal 434, so this
-- cannot delete rights belonging to somebody else's 434.
DELETE FROM tblgroupwise_rights_g2g
 WHERE menu_id = (SELECT id FROM tblmenumaster_g2g
                   WHERE access_link = '/module/hrit-solutions/attendance-management/my-office-hours'
                   LIMIT 1);

-- The menu row. Both predicates, so a 434 that is NOT this screen survives.
DELETE FROM tblmenumaster_g2g
 WHERE id = 434
   AND access_link = '/module/hrit-solutions/attendance-management/my-office-hours';

-- No sort_order repair is needed: the insert appended one past the maximum
-- under parent 93 and shifted no sibling, precisely because sort_order already
-- collides there on both hosts (309 and 162 both at 3; 163 and 433 both at 4).

-- ---------------------------------------------------------------------------
-- CONFIRM
-- ---------------------------------------------------------------------------
SELECT COUNT(*) AS menu_rows_left
  FROM tblmenumaster_g2g
 WHERE access_link = '/module/hrit-solutions/attendance-management/my-office-hours';
-- expect 0

SELECT COUNT(*) AS rights_rows_left
  FROM tblgroupwise_rights_g2g
 WHERE menu_id = 434;
-- expect 0

-- And the migrations table, if you deleted rows by hand instead of rolling
-- back - otherwise artisan believes these are still applied:
--   DELETE FROM migrations WHERE migration IN (
--     '2026_10_09_120000_add_my_office_hours_menu',
--     '2026_10_09_120100_grant_my_office_hours_rights');
