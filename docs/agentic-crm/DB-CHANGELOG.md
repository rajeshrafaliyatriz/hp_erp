# Agentic CRM — Database changelog

One row per migration or data update. A change is not finished until its row is complete.

**Dev changes since 2026-11-02 (slice 1). Nothing has run on live yet.**

| # | Slice | Migration or command (file) | Tables and columns | Applied on dev (date) | Applied on live (date) | Check query and evidence path | Rollback | Approved by |
|---|---|---|---|---|---|---|---|---|
| 1 | S1 | `2026_12_07_090000_create_g2g_product_master_table` | new `g2g_product_master` (16 columns, 4 unique keys, 2 indexes) | 2026-11-02 | not yet (needs the owner's go + scratch-table probe) | `SHOW CREATE TABLE` in `_evidence/s1-product-master-dev-2026-11-02.md` | `down()` drops the table | dev: no go needed |
| 2 | S1 | `2026_12_07_090100_ensure_g2g_signal_products_table` | creates `g2g_signal_products` correctly (BIGINT, utf8mb4) only where missing; live already has the old-shape table (0 rows) and is left unchanged | 2026-11-02 (created on dev) | not needed: no-op on live by design | same evidence file | none (not reversed) | dev: no go needed |
| 3 | S1 | data: `product-master:backfill --all --apply` (dev) | 5 shared products; 25 sub-products for organisation 1 and 25 for organisation 6 | 2026-11-02 (second run created nothing) | not yet; live preview (dry run): 5 products + 22 sub-products for organisation 6 | same evidence file | delete the rows (the table is new and nothing points at it yet) | dev: no go needed |

## Rules for filling a row

- File name sorts after the newest existing migration (see `DB-RULES.md`).
- "Applied on live" is filled only after an explicit owner "go", recorded in the slice file.
- Check query and evidence: the `SHOW CREATE TABLE` / `SHOW COLUMNS` output on both hosts and the row counts, saved under `_evidence/`.
- Rollback: for a migration, its tested `down()`; for a data update, how to null the new column or clear the new table.
- Data updates (backfills) are listed here too, with the artisan command, the `--apply` date per host and the counts printed.
