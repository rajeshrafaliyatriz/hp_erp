# S1 task 6: write API — 2026-11-02

Code only: **no database was changed** (no migration, no data update), so `DB-CHANGELOG.md` has no row for this task. Everything below is **verified** (command run, output read) unless marked.

## What was built (commits `66173abc`, `34aa2dab`)

| Route | Right on the Product Master leaf | Then, inside `ProductWriter`, also needs |
|---|---|---|
| `POST /api/product-master` kind `product` | `add` | **administrator** (profile's role key, never a name) |
| `POST /api/product-master` kind `sub_product` | `add` | the Portfolio's own right (administrator or HR while `signals.permissions.use_menu_rights` is off; the `/portfolio` menu right when it is on); plus the `add` right on Products or Services when "sell in CRM" is switched on |
| `POST /api/product-master` kind `item` (catalogue only) | `add` | `add` on Products or Services, by item type |
| `PUT /api/product-master/{id}` | `edit` | product: administrator; sub-product: Portfolio edit right (+ catalogue `add`/`edit`); item: catalogue `edit` of its own type |
| `POST /{id}/archive` | `delete` | a product: administrator, and no active sub-product under it |
| `POST /{id}/restore` | `edit` | a product: administrator; a sub-product: its product must be active |

Files: `app/Domain/ProductMaster/{ProductWriter,ProductAccess,ProductMasterInvalid}.php`, the controller (`store`, `update`, `archive`, `restore`, a `write()` wrapper that turns a refusal into `{status:0, message, errors}` with the right HTTP status), `routes/product_master.php` (8 routes now), `tests/Feature/ProductMasterWriteApiTest.php`.

Rules that matter:

- **Every access check runs before the first write, and every write runs in one transaction** (offer, catalogue item, master row). A refusal writes nothing; a failure part-way rolls everything back.
- **The Product Master is not a way around the screens it writes through.** The route's right says who may use the screen; the writer then checks the right of the screen that owns each record.
- A sub-product is a Portfolio offer: required offer id, product and readiness; a repeated offer id (this organisation's or a shared one) is a **409**; `readiness_confirmed` is **never** set here; the offer id and the product it belongs to never change; a shared offer is read-only.
- A product (own line) uses the same key and name rules and the same `g2g_signal_products` row as `ProductCatalog::createCustom`; a product cannot be renamed (the Portfolio groups its sub-products by that name).
- A catalogue item: item type product or service, only the organisation's own tax rate, a `PRD-`/`SVC-` number. Prices come from price books; only a base price is stored here (owner decision, 2026-11-02).
- Shared built-in rows (organisation 0) are 403; another organisation's row is 404 — checked in the controller's tenant-scoped lookup **and again** in the writer.
- Only a **duplicate-key** database error becomes a 409. Any other database error is thrown (a first version caught every `QueryException` and turned it into a 409; the rollback test caught that).

## Tests

`php vendor/bin/phpunit tests/Feature/ProductMasterWriteApiTest.php` → **OK (29 tests, 190 assertions)**. With `ProductMasterTest` (14) and `ProductMasterApiTest` (11): 54 tests.

Regression run, each file on its own: `ProductPortfolioIcpTest` 27 OK, `ProductIcpEditorTest` 9 OK, `ColumnExistsTest` 4 OK, `PhpFilesParseTest` 4 OK, `ProductCatalogBuiltInTest` 5 OK, `CustomProductRulesTest` 9 OK, `CustomProductReadinessTest` 5 OK, `ProductKeyTest` 1 OK, `OwnProductFitTest` 5 OK, `GuardableColumnCheckTest` 14 OK (one PHPUnit "metadata in doc-comment" deprecation in a file I did not touch).

**Three files fail, and fail identically without my change** (checked by running them with HEAD's versions of the two tracked files I modified, then restoring and byte-comparing): `CustomProductApiTest` (1 error, `g2g_companies.normalized_name` NOT NULL in the fixture), `ProductDrivenDiscoveryTest` (4 failures) and `ProductMatchingTest` (2 failures), both `no such table: crm_leads`: the Signals endpoints now query `crm_leads` and these fixtures never create it. All pre-existing; not touched here. (They are a small cleanup for whoever next works in Signals: S2.)

## Mutation sweep (the proof that the tests can fail)

Each rule was broken on purpose, one at a time, the tests were run, and the original bytes were restored (confirmed byte-for-byte after every sweep). 59 mutations: rights removed, checks removed, transactions removed, status codes changed, tenant filters removed, readiness forced to confirmed, and so on. The tooling is kept at `D:\dev\agentic-crm-research\scripts\mutation\product-master-write\` (`mutations.json`, `run.cjs`; outside the repo).

- **First sweep: 54 mutations, 37 killed, 17 survived.** The 17 were not noise; 15 were real rules no test pinned down: updating a product needs an administrator; another organisation's tax rate on three of four paths; a key already held by a master row whose offer was deleted; rollback on three of four transactions; item edit right; refusing a shared offer even when a row points at it; the parent must be an active product of this organisation; `productId` immutable on update; the `/portfolio` menu-right branch.
- **11 new tests closed them** (trigger-based rollback tests on SQLite, a direct writer-level test, a configuration test for the menu-rights branch, and so on). Re-run of the former survivors: all 15 killed.
- **Last gap found while writing this note:** the duplicate-key predicate had only ever been tested with SQLite's wording while production is MariaDB. Added a test with constructed exceptions (MariaDB by number, MariaDB by wording, SQLite, and a deadlock, an unknown column and a trigger abort that must not count) and 5 more mutations: all killed.
- **Final: 57 of 59 killed; 2 survive on purpose.** The controller's tenant-scoped lookup and the writer's own tenant check each cover the other, so removing either one alone changes nothing; removing both is killed (`C03x`, `C04x`).

**The sweep's first run was wrong, and I caught it before trusting it:** the runner passed `--no-ansi`, which PHPUnit 11 does not accept, so PHPUnit printed "Unknown option" and every mutation "failed", a false 100% kill. Found by reading the raw output of one mutation. Fixed: `--colors=never`; the runner now refuses to start unless the unmutated code is green under its own command, and counts a mutation as killed only when PHPUnit reports test failures (anything else is `INVALID`).

## The real column sets (read-only `SHOW COLUMNS`, both hosts; script `D:\dev\agentic-crm-research\scripts\g2g\s1_required_columns.php`)

The sqlite fixtures are simplified, so the columns an INSERT must supply were read from the real tables:

| Table | Must be supplied (NOT NULL, no default) | Writer supplies them |
|---|---|---|
| `crm_products` | `item_type`, `product_no`, `name`, `sub_institute_id` | yes |
| `g2g_product_offers` | `offer_id`, `name`, `parent_product` (varchar 50), `readiness_status` | yes (and a product's name is limited to 50 characters, so it always fits `parent_product`) |
| `g2g_signal_products` | `sub_institute_id`, `key`, `label`, `business_fit`, `icp_product_line` | yes |
| `g2g_product_master` | `sub_institute_id`, `product_key`, `kind`, `name` | yes |

Dev is `STRICT_TRANS_TABLES` (MariaDB 10.11.9); live is 10.1.48 with `sql_mode=NO_ENGINE_SUBSTITUTION`. The required sets are identical on both for the three tables that exist on live; `g2g_product_master` does not exist on live yet (expected). Two differences noted, both harmless here: live `g2g_product_offers` has 29 columns against dev's 28, and live `g2g_signal_products.sub_institute_id` is `int(10) unsigned` (known, D12).

## Routes (`php artisan route:list --path=api/product-master -v`)

8 routes, each behind `api`, `RequireApiToken` and `RequirePlatformRight:/module/crm/product-master,<view|add|edit|delete>`: GET list, GET options, GET `{id}`, GET `{id}/usage` (view); POST create (add); PUT `{id}`, POST `{id}/restore` (edit); POST `{id}/archive` (delete).

## Not verified

- **No real HTTP call with a real token against the dev database yet.** The write API is exercised only by the tests (in-memory sqlite with fixture tables). The real-table column check above covers the "NOT NULL with no default" class of error, but not other MySQL-only behaviour. The browser walk-through (task 13) does the first real write; it will also remove what it created, because the API has no hard delete.
- The `isDuplicate` MariaDB branch is tested with constructed exceptions, not a real duplicate-key error from MariaDB.
- A concurrent create of the same offer id (two requests at once) is handled by the unique index plus the 409 mapping, but no test races two requests.
- Nothing ran on live; nothing was pushed.
