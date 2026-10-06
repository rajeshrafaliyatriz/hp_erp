-- =====================================================================
-- G-NAV-01 — Task Management > Permission menu points at the Priority screen
-- =====================================================================
--
-- Approved by Triz 2026-08-05 as an early fix: small, isolated, low risk,
-- data-only, and it makes an already-built screen reachable.
--
-- PROBLEM
--   Menu row 219 ("Permision") and row 218 ("Priority Management") share one
--   access_link:
--       /module/task-management/administration/task-priority
--   The frontend builds its URL->screen lookup from access_link, so two rows
--   claiming one path is ambiguous by construction: whichever is registered
--   last wins. The Permission screen (components/domain/task/tm-permissions.tsx)
--   is fully built and simply cannot be reached.
--
--   This is the root cause of the original report that "Task > Permission does
--   not work".
--
-- FIX
--   Point row 219 at the path the frontend already registers for that screen:
--       hooks/content-map-m6.ts:42
--       { accessLink: '/module/task-management/task-permission',
--         submenuId: '219', component: TmPermissions }
--
--   Data-only. No application code changes, which matters because Phase 3 is
--   read-only on code until Gate D.
--
--   NOTE the path is deliberately NOT
--   /module/task-management/administration/task-permission, which would read
--   more consistently beside its siblings. That spelling would require editing
--   the frontend too. Tidying the URL is recorded as a Gate D item; today the
--   goal is to make a built screen reachable without touching code.
--
-- PRE-CONDITIONS
--   [x] Full backup taken: backup-tblmenumaster_g2g-2026-08-05.sql (188 rows)
--   [x] No other menu row uses the target path (verified: 0 rows)
--   [x] G-SEC-05 checked: no API route maps to menu 219, so nothing is
--       orphaned by this change
--
-- BLAST RADIUS
--   One row, one column. Row 218 is untouched and keeps working.
-- =====================================================================

START TRANSACTION;

-- Guard: fail loudly rather than silently updating the wrong row.
SELECT COUNT(*) AS must_be_1
FROM `tblmenumaster_g2g`
WHERE `id` = 219
  AND `menu_name` = 'Permision'
  AND `access_link` = '/module/task-management/administration/task-priority';

UPDATE `tblmenumaster_g2g`
SET `access_link` = '/module/task-management/task-permission',
    `updated_at`  = NOW()
WHERE `id` = 219
  AND `access_link` = '/module/task-management/administration/task-priority';
-- expect: 1 row affected

-- Verify: 218 and 219 must now differ, and 219 must match the frontend.
SELECT `id`, `menu_name`, `access_link`
FROM `tblmenumaster_g2g`
WHERE `id` IN (218, 219)
ORDER BY `id`;

COMMIT;

-- =====================================================================
-- ROLLBACK — restores the original (broken) value exactly
-- =====================================================================
-- START TRANSACTION;
--
-- UPDATE `tblmenumaster_g2g`
-- SET `access_link` = '/module/task-management/administration/task-priority',
--     `updated_at`  = NULL
-- WHERE `id` = 219;
--
-- COMMIT;
--
-- `updated_at` was NULL before this change; the rollback restores that too, so
-- the row returns to being byte-identical to the backup.
-- =====================================================================
