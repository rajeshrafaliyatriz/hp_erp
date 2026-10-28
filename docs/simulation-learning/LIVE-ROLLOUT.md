# FOURTH LIVE ROLLOUT (v4 content with an HDR sky and ambient occlusion): THE DATA IS IN (DONE 2026-10-28, after the owner said "add data in the live database") AND VERSION 2 WAS SWITCHED ON AT 17:21 THE SAME EVENING, once the new player was on production

Written to live (db3, 128.199.17.97): **19 asset rows and 2 version rows**, first kept without being played (nothing else changed: not one card, right or menu row), then switched on (below).

**Switched on (2026-10-28, 17:21 local = 11:51 UTC),** after the owner merged the pull requests (hp_erp #520, g2gv0 #314 and #316) and said the live site still showed the old simulations. Checked first, read-only: production served `index-BUn_GCar.js`, the same file as the build in g2gv0 `67a09e7` (it contains `image/vnd.radiance` and `aoRadius`), and both cover pictures returned 200. Then `php artisan simulations:promote 6 2 --database=live` and `php artisan simulations:promote 7 2 --database=live`: "now plays version 2 (it was 1)" for both. Integrity check afterwards (`live-tools/live_integrity_v5.php`): Pendulum Lab v2 played, 9 of 9 files; Ramp Lab v2 played, 11 of 11; 0 missing; stored sha256 identical to the versions tested on dev; the other simulations unchanged. Ramp Lab's card now reads "A baseball on a plank". **Still not verified from here:** playing them on the production site (no production login here); the owner's look is that check. The browser may show the old cover pictures for a while (same file names, cached).

**What went wrong right after the switch (about 17:45, found when the owner sent two screenshots of the live site).** Both lessons showed "This 3D model is not the file the lesson expects, so it was not shown." (the player refuses a file whose SHA-256 is not the one the lesson names). It was neither the lessons nor the files: every one of the 67 objects on the bucket matches its row by a whole-object hash (`live-tools/bucket_hash_check.php`, read-only), and the biggest is 2.3 MB. **Every response of `https://hp.triz.co.in` starts with the three bytes `EF BB BF`** (the home page, a JSON 401, a JSON 404; `erp.triz.co.in` and `lms.triz.co.in` do not), because `routes/api.php` on `main` starts with a UTF-8 byte-order mark since `dce6564a` (Rajesh Rafaliya, 17:35, "28-10-2026 Rajesh_2", which also changed `CrmAgentController.php`): PHP prints the mark when the route file is loaded, which is on every request. JSON and HTML tolerate it, so nothing else looked broken; a downloaded file is three bytes longer and its hash no longer matches. The older lessons (Eye Lab, Projectile Lab) are hit the same way, and so is any other binary download; going back to version 1 would not help. **Fix prepared, not deployed:** the local branch `fix/api-routes-byte-order-mark` (`b4a3d51c`: `main` minus exactly those three bytes, plus `tests/Unit/NoByteOrderMarkTest.php`, which fails on the file as it is on `main` and passes on the fixed one), or on the server, in the project folder, `sed -i '1s/^\xEF\xBB\xBF//' routes/api.php` (tested on a copy: byte-identical to the fixed file). Check from outside with `curl -s https://hp.triz.co.in/ | head -c 6 | xxd -p`: it must start `3c21`, not `efbbbf`. **Lesson for the next live switch:** the checks I had (the player's file name on production, the rows, the stored files' fingerprints) cannot see what the server puts in front of a download, and the commit that did it landed 14 minutes after the switch; after a live switch somebody has to open the lesson on the production site at once, or a token for a live user has to be available to fetch one file through the API.

| Step | What ran on live | Read-back |
|---|---|---|
| 0 | Read-only preflight (`live-tools/live_preflight_v5.php`): the five simulation tables have the same columns as dev; **none of the 19 files was registered** (every one of them is new since the third rollout); Pendulum Lab (id 6) and Ramp Lab (id 7) at version 1 only. And a read-only look at what production serves (`https://g2gv0.vercel.app/sim-runtime/index.html`): it names `index-DOF-rxwI.js`, which is the runtime of g2gv0 commit `193c9b3` (on `main`), **not** the v4 runtime of `14bf1c3` or the HDR one in the working tree. Its specification check accepts the asset kinds `model` and `image` only (strict) and has no `aoRadius` and no `image/vnd.radiance` anywhere in its 14 chunks, so a version that names an HDR sky is refused there. The two cover pictures are still a 404 there as well | |
| 1 | `php docs/simulation-learning/live-tools/run_asset_manifest.php docs/simulation-learning/live-tools/asset_manifest_v5_all.json --dry-run --live`, then without `--dry-run`: 19 platform assets: 7 models, 10 pictures and the 2 HDR skies (kind `hdr`, `image/vnd.radiance`); Pendulum Lab names 9 of them and Ramp Lab 11, with the baseball shared (each licence and credit is in the sample manifests) | ids 49 to 67, each "The file was already on the disk" (the bucket is shared with dev, so only the rows were written); 48 rows before, 67 after |
| 2 | `php artisan simulations:import D:/g2g-sim-runtime/public/dev-assets/<name>.spec.json --tenant=7 --update --hold --database=live` for `pendulum-lab` and `ramp-lab` (`MSYS_NO_PATHCONV=1`). `--hold` is new (see below): the file is saved as version 2 and **version 1 stays the one that is played**, and the card keeps saying what it said | "Kept version 2 of "Pendulum Lab" (id 6); version 1 is still the one that is played", the same for "Ramp Lab" (id 7) |
| 3 | Read-only integrity check (`live-tools/live_integrity_v5.php`): for the played and every kept version, each file the specification names has a registered row the organisation can reach, is on the bucket, and its first bytes are what the row says (a glTF, a PNG or JPEG, `#?RADIANCE`); and the stored specification is the one that was tested on dev | Pendulum v2: 9 of 9, Ramp v2: 11 of 11, 0 missing; stored sha256 `c1184ed7902fc4ba` and `23c79c8f73a4c730`, **identical** to dev simulations 11 and 12 at version 4, the ones that were played in the real app against the real backend. Eye 9 of 9, Projectile 35 of 35, Pendulum v1 4 of 4, Ramp v1 4 of 4 as before |

**Why version 2 was kept back first.** The production front end still runs the v3 runtime, which refuses a specification that names an HDR sky (its schema is strict: it refuses, it does not ignore). Switching the version now would turn two working simulations into an error on the production site until the owner deploys. So the data is in, and the switch is one command, run after the deploy.

**How it is switched on (this is what ran at 17:21; kept as the record). Condition: g2gv0 (branch milan1: the vendored runtime in `public/sim-runtime`, the host and the Create screen that know the `hdr` kind) is deployed to production, and preferably hp_erp (branch Milan-2) too:**

```
php artisan simulations:promote 6 2 --database=live
php artisan simulations:promote 7 2 --database=live
php docs/simulation-learning/live-tools/live_integrity_v5.php        (read-only; expect: v2 played, 0 missing)
```

`simulations:promote` checks the version again as a save would, then changes the played version and the card text together (Ramp Lab's card changes from "A ball on a ramp" to "A baseball on a plank"; do it with the command, not with a bare `UPDATE g2g_simulation SET current_version = 2`, which would leave the old card text). It is also the way back.

**Undo:** `php artisan simulations:promote 6 1 --database=live` (and `7 1`) once version 2 is played; before the switch there is nothing to undo, because version 2 was never played. The 19 asset rows are additive and harmless; to remove them anyway: `UPDATE g2g_simulation_asset SET deleted_at = NOW() WHERE id BETWEEN 49 AND 67` (a soft delete: the files stay on the bucket).

**The scripts** named above are kept in `docs/simulation-learning/live-tools/` (they use this machine's absolute paths, `C:/Users/MILAN/Downloads/hp_erp` and `D:/g2g-sim-runtime`; the two `live_*` scripts only read; `run_asset_manifest.php` writes only with `--live` and not at all with `--dry-run`).

**The new commands live in hp_erp only.** `--hold` on `simulations:import` and `simulations:promote` are artisan commands: they were run from the developer machine against the live database, and need no deploy to do that. Behind them is `SimulationStore::addVersion(..., makeCurrent: false)` and `SimulationStore::promote()`; 2 new tests, the whole simulation set (167 tests) passes.

**Not verified.** That version 2 plays on the production site: it cannot until the deploy, and there is no production login token here. What stands in for it: the stored text is byte-identical (same canonical sha256) to the version played in the real g2gv0 app and the real backend on dev (every one of the 9 and 11 files fetched with a 200, the sky as `image/vnd.radiance`), and every file it names is registered, reachable and correct on live. After the switch, open both simulations once on the production site.

---

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
