# THIRD LIVE ROLLOUT: the two sample simulations (Pendulum Lab, Ramp Lab) for organisation 7 (DONE 2026-10-28, after the owner said "add in the live database")

Only data was written: two simulation rows. Each step was previewed or read first, run, and read back with read-only scripts.

| Step | What ran on live (db3) | Read-back |
|---|---|---|
| 0 | Read-only preflight (`live_preflight.php`): the three simulation tables have the same columns as dev; the slugs were free; **all five files the two samples use are already registered on live as platform assets** (ids 10 cannonball, 13 block, 26 landing pad, 37 concrete, 41 sky: the Eye and Projectile rollouts registered the same downloaded files), so no file was registered or uploaded | 48 asset rows before and after |
| 1 | `simulations:import D:/g2g-sim-runtime/public/dev-assets/pendulum-lab.spec.json --tenant=7 --database=live --cover=/simulations/covers/pendulum-lab.webp` | "Added Pendulum Lab (id 6, published, version 1) for organisation 7": Science, introductory, 15 min, 2 missions |
| 2 | the same for `ramp-lab.spec.json` | "Added Ramp Lab (id 7, published, version 1) for organisation 7": Mechanics, 8 min |

Integrity check on live: every file each simulation names is registered and reachable by its organisation (Eye 9 of 9, Projectile 35 of 35, Pendulum 4 of 4, Ramp 4 of 4; 0 missing). The menu row 481 and the rights rows (organisation 6: 3, organisation 7: 3) are unchanged; nothing else on live was written.

**Behaviour to expect.** The specifications are `g2g.sim/2`. The deployed front end (g2gv0.vercel.app) already serves the new runtime (its `sim-runtime/index.html` names the same index file as the vendored build) and the sample zips. The two cover pictures were still a 404 there at the time (commit `fe5bf82` of g2gv0 is not deployed), so the cards show their generated cover until it is. I could not call the production API (no live token), so I have not watched them play on the production site: the rows and the files are verified, the play is not.

**Undo (reverse order):** `php artisan tinker` with `SimulationStore::softDelete` on ids 6 and 7, or `php remove_sims_org.php live 7 pendulum-lab,ramp-lab apply` (a soft delete: restorable); or, to remove the rows outright, `DELETE FROM g2g_simulation_version WHERE simulation_id IN (6,7); DELETE FROM g2g_simulation WHERE id IN (6,7);`. No asset, tenant or rights row was added, so nothing else needs undoing.

---

# SECOND LIVE ROLLOUT: the eye and projectile lessons for organisations 7 and 6 (DONE 2026-10-27, after the owner said "run the live rollout")

Each step was previewed (`--pretend` / `--dry-run`), run, and read back with read-only scripts before the next.

| Step | What ran on live (db3) | Read-back |
|---|---|---|
| 1 | `migrate --database=live --path=database/migrations/2026_12_04_090000_create_g2g_simulation_asset_table.php --force` (427 ms) | `g2g_simulation_asset` exists; migration recorded |
| 2 | `simulations:asset-add --platform ... --database=live` for 33 files (2 models + 7 images of the eye; 24 models of the projectile scene) | 33 rows; each "The file was already on the disk" (the bucket is shared with dev, nothing re-uploaded) |
| 3 | `simulations:import <spec> --tenant=7|6 --database=live --cover=/simulations/covers/<id>.webp` (4 imports, `MSYS_NO_PATHCONV=1`) | simulations #2 (org 7 eye), #3 (org 7 projectile), #4 (org 6 eye), #5 (org 6 projectile), all published, version 1; #1 is the Traffic Control Room (org 6) |
| 4 | `simulations:grant --tenant=7 --authors --database=live` (organisation 6 needed nothing: its 3 rows exist from the first rollout) | organisation 7: Admin (profile 19) view+add+edit+delete, Employee (20) and HR (21) view; rights rows on the menu row: org 6: 3, org 7: 3, none for any other organisation |

Integrity check on live: every file each lesson names (9, 24, 9, 24) is registered and reachable by its organisation; 0 missing.

**Still needed for the screen to open on the production site: deploy the code.** hp_erp (branch Milan-2, `3379fe8a`: the asset route, validation, grant command) and g2gv0 (branch milan1, `7069969`: the screen, the host that fetches models and images, `public/sim-runtime`, `public/simulations/covers/eye-lab.webp` and `projectile-lab.webp`). Until then the sidebar shows the entry and it opens the placeholder. To hide the entry meanwhile: `UPDATE tblmenumaster_g2g SET status = 0 WHERE access_link = '/module/lms/simulation-learning';` (and back to 1 after the deploy).

Undo for this rollout (reverse order): `php artisan simulations:grant --tenant=7 --revoke --database=live` (removes the view-only rows; the Admin's authoring row is changed in Role & Permissions or with a DELETE on that one row); then `DELETE FROM g2g_simulation_version WHERE simulation_id IN (2,3,4,5); DELETE FROM g2g_simulation WHERE id IN (2,3,4,5);` then `DROP TABLE g2g_simulation_asset;` and `DELETE FROM migrations WHERE migration = '2026_12_04_090000_create_g2g_simulation_asset_table';`.

---

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
