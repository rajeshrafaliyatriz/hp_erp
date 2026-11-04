# S1 task 5: menu row, grant command and read API — 2026-11-02

Dev database (`mysql` connection). Everything below is **verified** (command run, output read) unless marked.

## What was built (commits `a0dc79a3`, `782748d8`)

- `database/migrations/2026_12_07_090200_create_product_master_menu_row.php`: one level-2 row "Product Master" under the CRM root (`/module/crm`, shown as "Sales & Marketing Intelligence"), access_link `/module/crm/product-master`. **Grants nothing.** Idempotent; does nothing without the menu tables or the CRM root.
- `php artisan product-master:grant --tenant=<id> [--apply] [--revoke] [--database=live]`: gives one organisation's administrator profile(s) (decided by `RoleKey::fromProfile`, never a name match) view/add/edit/delete on the leaf only. Dry run unless `--apply`; repeatable; an administrator who cannot already see the CRM root is reported and left alone (the root is never granted).
- `routes/product_master.php` (registered in `bootstrap/app.php` like `platform.php` and `ai.php`): `GET /api/product-master`, `/options`, `/{id}`, `/{id}/usage`, behind `platformright:/module/crm/product-master,view`. **No administrator bypass**: `MenuRight::can` needs an explicit rights row.
- `ProductMasterController`: the organisation always comes from the token; the organisation's own rows and the shared (tenant 0) built-in products are visible; another organisation's record, offer pointer or catalogue pointer gives 404 or is left out. Sample signals are not counted. `?status=` filters per row so an archived sub-product shows under an active product.

## Run on dev

1. `php artisan migrate --pretend --path=…090200_create_product_master_menu_row.php`, then the real run: menu row **id 505**, parent 199, level 2, page_type `page`, sort_order 29, status 1, 0 rights rows.
2. `product-master:grant --tenant=6` (dry run): 1 administrator profile (16), would grant.
3. `product-master:grant --tenant=6 --apply`: profile 16 granted; a second apply said "already has the screen".
4. Leaf-only check: `tblgroupwise_rights_g2g` max id 45416 and exactly 1 row with id ≥ 45416. `MenuRight::can(16, 199, 6, 'view')` true (already had the CRM root), `can(16, <leaf>, 6, 'view')` true, `can(17 /*Employee*/, <leaf>, 6, 'view')` false.
5. Both tables have every column the migration and command write, on dev and live (from the S0 baseline). `sub_institute_id` is TEXT in both rights and menu tables.

## Tests (`tests/Feature/ProductMasterApiTest.php`, 11 tests, 103 assertions; with the earlier files 33 tests, 219 assertions)

Menu migration (one leaf, correct parent/level/sort, grants nothing, repeatable, nothing without the CRM root); grant command (dry run writes nothing, only that organisation's administrator, never the root, idempotent, revoke, missing `--tenant` invalid); rights (no token 401, employee 403, **administrator before the grant 403**, granted 200, another organisation's administrator 403); list nesting and counts (shared flag, readiness, sold-in-CRM, signals per product for this organisation only, no count for a product Signals has no value for); search and status filters; catalogue-only items; options labels; one record (offer, catalogue item, a catalogue pointer into another organisation not leaked, 404 for another organisation's product and sub-product, unknown and non-numeric ids); usage (signals, price books excluding deleted and other organisations', quotes counted once, base price, empty state).

**Mutation checks** (each reverted, files confirmed byte-identical): (1) `show()` reading any organisation's row, (2) no rights middleware on the routes, (3) catalogue pointer without the organisation filter, (4) signal counts across organisations: each failed exactly the matching test.

## Not done / not verified

- The API was exercised only by the tests (sqlite), not by a real HTTP call with a real token on the dev database: that happens in task 13 (browser walk-through).
- Nothing ran on live. The live menu migration and grant need the owner's "go" and the scratch-table probe.
- `options` currently needs the same right as the list; slice 2 will widen it for the Signals run screen when that consumer exists.
