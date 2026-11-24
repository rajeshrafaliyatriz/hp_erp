# S15: do the History tabs work? (owner's request of 24 Nov 2026) - evidence

Dev database, organisation 6; nothing on live. No screenshots are kept in this repository (the live server's old git shows committed images as always "modified" and blocks `git pull`); the logs are plain text.

## Found: History was EMPTY for a record that had just been changed
`before-the-fix-history-empty.log`: a test Sub-product was created through the drawer (About + Sell), renamed, its price changed 123.5 to 150, archived and restored. The record itself was right (name "... v2", price 150.00, active) and the **event store held all 8 events** (created x3, name changed x3, archived, restored; `g2g_event` ids 11278 to 11285), but `GET /api/product-master/{id}/history` answered **0 lines** and the History tab showed nothing.
Cause (read in the database): the History read `g2g_audit_log`, a COPY that the scheduled job `events:project` fills. On this host that job last ran on 21 Oct (audit log newest event 7100, event store at 11285: 4,185 events behind). The runbook says `schedule:run` is not active on live either, so the History tab would be empty or stale there too (live not checked).

## Fixed
- hp_erp `ProductHistory` reads the **event store** (`g2g_event`, written at the moment of the change) and keeps the old projection only for a host that has no event store. Same rules: lines of the caller's organisation only; HR never sees a price line. `ProductHistoryApiTest`: 13 tests (3 new); a mutation that ignores the event store fails 2 of them.
- g2gv0: a Product page now has a **History tab** (Overview, Sub-products, Signals & ICP, History; a Product that only the Portfolio has gets Sub-products and History); a "Created" line no longer lists the system's own fields ("Sub institute id: empty -> 6", "Kind", "Origin", pointer ids). Whole front-end suite 3,758 pass, tsc 0, eslint 0; three mutations each fail a named test.

## Verified in a real browser (`browser-history-tabs-7-of-7.log`)
The Sub-product's History tab lists Created (Record, Sell in CRM, About: Product, Name, Status, Number, Unit price 123.50 ...), Changed (Name ... v2, Unit price 123.50 -> 150.00) and the archive and restore (Status active -> archived -> active), each with who ("Scholar Clone") and when; the Product page has the History tab (empty message when nothing was changed); Commercial Bundle has Sub-products and History. Test rows deleted afterwards, counts equal the state after the repair, `product-master:check --tenant=6` 0 errors.

## Not done / to know
- The test record's 8 events stay in the append-only event store (events are never deleted); they belong to a record that no longer exists.
- Edits made through the OLD CRM form or the old Portfolio endpoints may not write History events (only the one-record writer does): not checked.
- A Product's own name, status and details edited from the Product page's "Edit this product" go through the Product API; whether that writes a History event was not checked (the Product tab showed "No changes recorded yet" for a built-in Product nobody edited).
