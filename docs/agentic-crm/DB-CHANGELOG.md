# Agentic CRM — Database changelog

One row per migration or data update. A change is not finished until its row is complete.

**No database change has been made yet** (as of 2026-11-02). S0 only measures, read-only.

| # | Slice | Migration or command (file) | Tables and columns | Applied on dev (date) | Applied on live (date) | Check query and evidence path | Rollback | Approved by |
|---|---|---|---|---|---|---|---|---|
| | | | | | | | | |

## Rules for filling a row

- File name sorts after the newest existing migration (see `DB-RULES.md`).
- "Applied on live" is filled only after an explicit owner "go", recorded in the slice file.
- Check query and evidence: the `SHOW CREATE TABLE` / `SHOW COLUMNS` output on both hosts and the row counts, saved under `_evidence/`.
- Rollback: for a migration, its tested `down()`; for a data update, how to null the new column or clear the new table.
- Data updates (backfills) are listed here too, with the artisan command, the `--apply` date per host and the counts printed.
