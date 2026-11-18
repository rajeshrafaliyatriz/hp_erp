# S1 tasks 2 to 4 on the dev database — 2026-11-02

Connection `mysql` (dev, 202.47.117.220, database `hp_erp`, MariaDB 10.11.9). Commands run from `C:\Users\MILAN\Downloads\hp_erp`. Live was only read (a dry run). Everything below is **verified** (command run, output read).

## Migrations run on dev (one file at a time)

1. `php artisan migrate --pretend --path=database/migrations/2026_12_07_090000_create_g2g_product_master_table.php`: showed the `SHOW TABLES LIKE` guard and the `CREATE TABLE` without executing.
2. `php artisan migrate --path=database/migrations/2026_12_07_090000_create_g2g_product_master_table.php`: ran.
3. `php artisan migrate --path=database/migrations/2026_12_07_090100_ensure_g2g_signal_products_table.php`: ran (the table was missing on dev).

Before: `migrate:status` listed all three files (including the original `2026_12_05_090000_create_g2g_signal_products_table`) as Pending on dev.

## `SHOW CREATE TABLE g2g_product_master` on dev (matches the design)

```
CREATE TABLE `g2g_product_master` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sub_institute_id` bigint(20) unsigned NOT NULL,
  `product_key` varchar(50) NOT NULL,
  `kind` varchar(12) NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(191) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'active',
  `origin` varchar(20) NOT NULL DEFAULT 'manual',
  `signal_key` varchar(20) DEFAULT NULL,
  `portfolio_name` varchar(50) DEFAULT NULL,
  `offer_row_id` bigint(20) unsigned DEFAULT NULL,
  `crm_product_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `g2g_pm_tenant_key` (`sub_institute_id`,`product_key`),
  UNIQUE KEY `g2g_pm_tenant_signal` (`sub_institute_id`,`signal_key`),
  UNIQUE KEY `g2g_pm_tenant_offer` (`sub_institute_id`,`offer_row_id`),
  UNIQUE KEY `g2g_pm_tenant_crm` (`sub_institute_id`,`crm_product_id`),
  KEY `g2g_pm_tenant_parent` (`sub_institute_id`,`parent_id`),
  KEY `g2g_pm_tenant_kind_status` (`sub_institute_id`,`kind`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

No `ON UPDATE` on either timestamp. `g2g_signal_products` was created on dev with `bigint(20) unsigned` ids and `utf8mb4_unicode_ci`, same three unique keys as the original; 0 rows.

## Backfill

- Dry run, organisation 6 (dev): would create 5 shared products and 25 sub-products; nothing written.
- **Dry run against live** (`--database=live`, read only; live has no Product Master table yet): organisation 6 would create **5 shared products and 22 sub-products** (the owner's current Portfolio), with one noted oddity: "offer EB has the same id as its product 'Enterprise Brain'; kept as a sub-product".
- Apply on dev, `--all` (organisations 1 and 6): organisation 1 created 5 shared products and 25 sub-products; organisation 6 created 25 sub-products and found the 5 shared products already there.
- Second apply: everything "already there", nothing created.
- Contents after (dev): shared products `scholarclone` ("ScholarClone", signal key `scholar`, group "Scholar K-12"), `enterprise_brain` (`eb`, group "EB"), `g2g` (`g2g`, "G2G"), `he` ("Higher Education", no signal key, "HE"), `bundle` ("Commercial Bundle", no signal key, "Bundle"). Organisation 6 sub-products per product: bundle 2, enterprise_brain 5, g2g 10, he 1, scholarclone 7. Orphans (sub-product whose parent is missing): 0.

## Tests

- `tests/Feature/ProductMasterTest.php`: 14 tests, 101 assertions, all pass. It runs the real migration files on in-memory sqlite and checks: both migrations are safe to run twice; unique keys are per organisation and allow many empty pointers; a dry run writes nothing; apply puts sub-products under the right parents (including HE and Bundle, which have no Signals value); problems are listed (orphan offer, repeated offer id, EB same id); a repeat run changes nothing; one organisation never gets another's offers; catalogue items become their own items and deleted ones are skipped; every spelling of a product reaches one row and unknown values reach nothing; an ambiguous offer id finds nothing; the most specific row wins unless the signal disagrees with its own offer; an organisation never sees another's own products; the command defaults to a dry run; a dry run works before the table exists and apply refuses.
- **Mutation checks** (a passing test must be able to fail): (1) the offer always wins a conflict → `test_the_most_specific_row_wins...` fails; (2) never look for an existing row → the repeat and isolation tests error; (3) no organisation filter on offers → three tests fail. Each mutation was reverted and the files were confirmed byte-identical to the originals; the clean run passes.
- Wider run with `PhpFilesParseTest`, `ColumnExistsTest`, `GuardableColumnCheckTest` and `CustomProductApiTest`: 44 tests, 1 error, which is the pre-existing `CustomProductApiTest` failure (sqlite NOT NULL on `g2g_companies.normalized_name`) that also fails on the unchanged code.

## Not done / not verified

- Nothing was run on live except the read-only dry run. The live migration needs the owner's "go" and the scratch-table probe (`DB-RULES.md`).
- The migrations were never executed on MariaDB 10.1; the DDL is plain (no JSON, no functional index, explicit charset) and will be probed on live first.
- Live Portfolio counts drift (the owner edits it); the live dry run is point-in-time.
