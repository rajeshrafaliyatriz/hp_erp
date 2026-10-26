# Simulation Learning: putting it on live (db3) for tenant 6

**STATUS: DONE on 2026-10-26, after the owner said "run the live rollout".** The first attempt had been refused by the permission classifier
("Production Deploy") and was not worked around; after the owner's instruction the steps below ran one at a time, each read back before the next.

## Result on live (read back with a read-only script, host `lms.triz.co.in`, db `hp_erp`, MariaDB 10.1.48)

| Step | What ran | Read-back |
|---|---|---|
| 2 | `migrate --database=live --path=...090000_create_g2g_simulation_tables.php` (batch 380, 858 ms) | 4 tables exist, all empty |
| 3 | `migrate --database=live --path=...090100_create_simulation_learning_menu_row.php` (batch 381, 823 ms) | `tblmenumaster_g2g` 272 to 273 rows; new row **id 481**, parent 4 (LMS), level 2, sort 9, status 1; **1 rights row**: profile 16 (organisation 6 Admin) view/add/edit/delete |
| 4 | `simulations:import ... --tenant=6 --database=live --cover=/simulations/covers/traffic-control-room.webp` | `g2g_simulation` id 1 (organisation 6, published, version 1, 4 missions, 15 min, cover set); `g2g_simulation_version`: 29,991 bytes, sha256 matches the stored text |
| 5 | `simulations:grant --tenant=6 --dry-run`, then for real | exactly profiles 18 (HR) and 17 (Employee), view only; 3 rights rows in total, **0 for any other organisation**, no duplicates |

The application's own store code, pointed at live, returns the Traffic Control Room for organisation 6 (learner and manager) and nothing for organisations 7
and 1; organisation 7 asking for simulation 1 directly gets nothing.

Two notes from the run. (a) In Git Bash, give the cover as `MSYS_NO_PATHCONV=1 php artisan simulations:import ... --cover=/simulations/...`: otherwise the shell
rewrites the leading `/` into a Windows path and the importer (correctly) refuses it before writing anything; that is what happened on the first try. (b) The
database is ready but **the code is not deployed**: until hp_erp (routes, controller, store) and g2gv0 (the screen, `public/sim-runtime`, the cover) are deployed,
the sidebar on the production site shows the new entry to organisation 6 and opens a placeholder, and `/api/lms/simulations` does not exist there. To hide the
entry until then: `UPDATE tblmenumaster_g2g SET status = 0 WHERE access_link = '/module/lms/simulation-learning';` (and back to 1 after the deploy).

The rest of this file is the plan as it was written, kept for the undo and for the next organisation.

Written 2026-10-26. Everything below was prepared in this order, to run from `C:\Users\MILAN\Downloads\hp_erp`. Each step was rehearsed on the dev host
(`web.triz.co.in`, connection `mysql`) and checked read-only against live.

## What was checked on live first (read-only, 2026-10-26)

- Host `lms.triz.co.in`, database `hp_erp`, MariaDB 10.1.48 (db3). The four simulation tables and the menu row do **not** exist yet.
- `SHOW COLUMNS` on `tblmenumaster_g2g` (14 columns), `tblgroupwise_rights_g2g` (16) and `migrations` (3): identical to the dev host.
- Menu ids differ between hosts (Capability Gap Inbox is 488 on dev, 475 on live), so nothing names an id; every row is found by `access_link`.
- `Course Builder` rights on live: 28 rows over 10 organisations, but **only profile 16 (organisation 6's Admin) has an authoring bit** (add/edit/delete);
  the Employee profiles of eight organisations hold it view-only. The first version of the menu migration would have copied all 28 (a new menu entry for
  those employees); it now copies authors only, so on live it writes exactly one rights row (profile 16: view, add, edit, delete). Covered by a test.
- Tenant 6 profiles on live: 16 Admin, 17 Employee, 18 HR (+ 46..51). Only 16, 17, 18 hold My Learning, so `simulations:grant` adds view-only rows for 17 and 18.

## Order (deploy the code first, then the database)

1. **Deploy the code** that the screen needs (this is not done by any of the commands below):
   - hp_erp: `routes/api.php`, `app/Http/Controllers/Api/LmsSimulationController.php`, `app/Services/Lms/SimulationStore.php`,
     `app/Services/Lms/SimulationException.php`, `app/Console/Commands/ImportSimulation.php`, `app/Console/Commands/GrantSimulationLearning.php`, the two migrations.
   - g2gv0: `services/lms/simulations.ts`, `hooks/use-simulations.ts`, `lib/simulation-host.ts`, `components/domain/lms/simulations/`, `hooks/content-map-m4.ts`,
     `lib/gtg-navigation.ts`, `next.config.mjs` (the `/sim-runtime` headers), and the static files `public/sim-runtime/` (the runtime build) and
     `public/simulations/covers/traffic-control-room.webp`.
   Nothing is committed or pushed yet (you did not ask for it); say the word and it can be.
2. **Create the tables** (four new tables, nothing existing is touched). Optional preview first:
   ```
   php artisan migrate --database=live --path=database/migrations/2026_12_03_090000_create_g2g_simulation_tables.php --pretend
   php artisan migrate --database=live --path=database/migrations/2026_12_03_090000_create_g2g_simulation_tables.php --force
   ```
   Always give `--path`: a bare `migrate --database=live` would also run the five calendar migrations live is still missing (they use JSON and fail on 10.1).
3. **Add the menu entry** (the row in `tblmenumaster_g2g` you asked for, under LMS, `access_link /module/lms/simulation-learning`, plus the one rights row):
   ```
   php artisan migrate --database=live --path=database/migrations/2026_12_03_090100_create_simulation_learning_menu_row.php --force
   ```
4. **Put the Traffic Control Room in tenant 6's gallery** (one simulation row and one immutable version row, owner = organisation 6):
   ```
   php artisan simulations:import D:/g2g-sim-runtime/src/platform/specs/traffic-control-room.json --tenant=6 --database=live --cover=/simulations/covers/traffic-control-room.webp
   ```
5. **Offer it to tenant 6's learners** (view-only rows; dry run first, it changes nothing):
   ```
   php artisan simulations:grant --tenant=6 --dry-run --database=live
   php artisan simulations:grant --tenant=6 --database=live
   ```

## Expected result (read it back in any SQL client on db3)

```sql
SELECT id, menu_name, parent_id, level, access_link, status, sort_order FROM tblmenumaster_g2g WHERE access_link = '/module/lms/simulation-learning';
-- one row: parent_id = 4 (LMS), level 2, status 1, sort_order 9 (id 481 if nothing else was added meanwhile)
SELECT r.profile_id, p.name, p.sub_institute_id, r.can_view, r.can_add, r.can_edit, r.can_delete
  FROM tblgroupwise_rights_g2g r JOIN tbluserprofilemaster p ON p.id = r.profile_id
 WHERE r.menu_id = (SELECT id FROM tblmenumaster_g2g WHERE access_link = '/module/lms/simulation-learning');
-- three rows, all organisation 6: profile 16 Admin (1,1,1,1), profile 17 Employee (1,0,0,0), profile 18 HR (1,0,0,0)
SELECT id, sub_institute_id, slug, title, category, status, current_version, missions, cover_url FROM g2g_simulation;
-- one row: organisation 6, slug traffic-control-room, published, version 1, 4 missions, category Engineering
SELECT simulation_id, version, LENGTH(spec) AS bytes, sha256 FROM g2g_simulation_version;
-- one row: version 1
SELECT migration, batch FROM migrations WHERE migration LIKE '2026_12_03_09%';
-- two rows
```

Then sign in as organisation 6's admin and as an employee: the sidebar shows **LMS > Simulation Learning**, the gallery shows one card with the scene as its picture.

## Undo (reverse order; nothing else is touched)

```
php artisan simulations:grant --tenant=6 --revoke --database=live
```
```sql
DELETE FROM g2g_simulation_progress;  -- only if anyone has played; their rows are per learner
DELETE FROM g2g_simulation_version WHERE simulation_id IN (SELECT id FROM g2g_simulation WHERE sub_institute_id = 6);
DELETE FROM g2g_simulation WHERE sub_institute_id = 6;
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id = (SELECT id FROM tblmenumaster_g2g WHERE access_link = '/module/lms/simulation-learning');
DELETE FROM tblmenumaster_g2g WHERE access_link = '/module/lms/simulation-learning';
DROP TABLE g2g_simulation_progress, g2g_simulation_tenant, g2g_simulation_version, g2g_simulation;
DELETE FROM migrations WHERE migration IN ('2026_12_03_090000_create_g2g_simulation_tables', '2026_12_03_090100_create_simulation_learning_menu_row');
```

## If you want me to run it

Add a permission rule so these exact commands are allowed, or leave auto mode for the session, and say "run the live rollout". I will run them one at a time and
read each result back against the expectations above. I will not retry a blocked command another way.
