# S2 — backfills, menu row and grant, run on the dev database (3 Nov 2026)

Dev database only (connection `mysql`, MariaDB 10.11.9). Live was not touched. Each command ran as a dry run first, then `--apply`, then `--apply` again to prove a re-run changes nothing. Summaries are saved beside this file as `s2-backfill-*-dev-apply-2026-11-03.txt` and `…-rerun-…`.

## The three backfills (organisation 6, 48 signals) — verified

| command | dry run said | applied | second run |
|---|---|---|---|
| `signals:backfill-date-status` | 19 event_date_stated, 2 source_date_only, 27 undated; 9 rows carry the stored assessment note | filled 19 / 2 / 27 | 48 already filled, nothing written |
| `signals:backfill-reviews` | 3 signals with a decision (dismissed 2, follow_up 1); 0 would use the `updated_at` fallback | 3 history rows | 3 already there, 0 created |
| `signals:backfill-product` | 40 resolvable (all by `business_fit`), 8 with no product value, 0 with a value but no matching Product Master row | 40 set | 40 already set |

The dry-run counts equal the independent measurements taken at the design gate (dev 19 / 2 / 27 dated, 3 reviewed, 48 signals, 9 assessment notes).

**Independent SQL check (not the commands' own output):**
- `date_status` by value {event_date_stated 19, source_date_only 2, undated 27}; **0** rows whose status disagrees with their own `event_date` / `source_published_at`.
- History: {dismissed/backfill/Dismissed 2, follow_up/backfill/Follow-up 1}; **0** signals with a status other than New and no history row.
- `product_master_id` set on 40; **0** pointing at a missing row or another organisation's row; by product: ScholarClone 31 (`scholar`), Enterprise Brain 6 (`eb`), G2G 3 (`g2g`); the 8 left NULL are exactly the rows with no `business_fit` (honestly unresolved, not guessed).

## Menu row and grant — verified

- `2026_12_08_090300_create_signal_review_menu_row` ran on dev (182 ms): row id **506**, "Signal Review", `/signal-review`, level 3, page_type page, status 1, sort 6, parent "Market Signals" (`/module/crm/signals`).
- `signals:grant-review --tenant=6`: dry run said "would grant" to profile 16 only; `--apply` granted view/add/edit/delete; a second `--apply` said "already has the screen". `tblgroupwise_rights_g2g` holds **one** row on the leaf (id 45417, profile 16, organisation 6). **No other rights row was created that day on any other menu id** (the command never writes an ancestor).
- The parent was already visible to profile 16, which the command checks before it grants (it refuses to grant a leaf the profile could not reach).

## Not verified here

- Nothing of this ran on live. The live counts to expect for organisation 6 are 16 / 8 / 49 (date status) and 5 history rows; they are untested until the owner's go.
- The sidebar rendering of the new leaf (the browser walk-through follows).
