# S2 — Signal Review in a real browser, on the dev database (3 Nov 2026)

Tenant-6 administrator (user 28, profile 16), Chromium via Playwright, local Laravel on `127.0.0.1:8000` against the **dev** database, Next.js on `localhost:3000`. Script: `D:\dev\agentic-crm-research\scripts\browser\s2-signal-review-walkthrough.cjs` (phone check: `s2-phone-check.cjs`); test data: `s2_browser_db.php seed|cleanup`. Screenshots and `results.json`: `D:\dev\agentic-crm-research\evidence\s2-walkthrough\run2\` and `\phone\`. All of this is **verified** (I ran it and read the output and the screenshots).

## Result: 71 of 71 checks passed (run 2), plus 7 of 7 phone checks

| Section | What was proved |
|---|---|
| 0. Screen | Heading, tabs Signals/Runs, summary tiles; both `/module/crm/signal-review` and the menu's `/signal-review` open it; Status opens on "Needs decision", Freshness on All (undated signals not hidden) |
| 1. List and facets | "Needs decision" lists exactly the 5 undecided test signals; badges say what kind of date it is ("Event 13 Nov 2026", "Published 24 Oct 2026", "Date unknown"); Status All lists 8 and never the other organisation's signal; Freshness Date unknown = the undated one, Older = the 200- and 120-day ones, Last 30 days = the four expected; Product facet filters by `product_master_id` and puts it in the address; sort "Newest evidence" puts dated before undated; filtered-empty with Clear filters |
| 2. Drawer | Why it matters, Evidence, History ("No decisions yet."), buttons Contacted / Reviewed / Not relevant… (no Reopen on an undecided signal) |
| 3. Not relevant | No reason → blocked on screen, nothing sent; "Other" without a note → blocked; with a reason → one POST, 200; status chip changes in place; History gains the entry with reason and note; DB row is `dismissed / stale / New→Dismissed / via signal_review / by the admin`; after a dismissal only Reopen… is offered; reopen adds a second row and the first is untouched (append-only) |
| 4. Earlier decisions | A decision made on the original screen is tagged "original screen"; a seeded dismissal shows its reason and note; a rebuilt record is tagged "earlier record" and shows no reason it never had |
| 5. Conflict | While the drawer was open the original screen's Contacted ran (200); pressing Reviewed then showed "Someone decided first" with who/what/when and Continue anyway / Cancel; Continue anyway added our decision after theirs, both kept |
| 6. Runs | "Runs are started by hand. No schedule is switched on."; a run with unknown cost shows a dash; tab kept in the address |
| 7. API refusals | 422 keyed on `reason_code` / `note`; unknown decision 422; reopen on a New signal 409 `unchanged`; wrong `expected_status` 409 `conflict`; none of the refused attempts left a history row; valid dismissal 200 with data + history entry; same dismissal again 409, no second row |
| 7. Original screen | Its Not relevant button (no reason) still answers 200 and is stored as reason `other` + the legacy note + `via legacy_screen`; pressing it twice adds no row; an unknown reason sent anyway is 422 |
| 7. Promote | First press 201 and a lead; second press finds the same lead (`created=false`); exactly one `promoted` history row |
| 7. Isolation | Another organisation's signal: 404 on read, history, decide, original-screen decide and promote; the feed never lists it; `date_status=undated` returns only the undated signal; unknown `freshness` 422 |
| 8. Error state | A failing list shows "Signals could not be loaded" with Retry; Retry loads the list |
| 9. Phone (390 px) | No sideways scroll; drawer exactly 390 wide and inside the screen; buttons and History reachable; decision dialog 358 wide and inside the screen, submit on screen; Escape closes the dialog and leaves the drawer |
| End | No unexpected failing API request; no uncaught page errors; no console errors |

## Events (verified from the tables, not from the script)

For the 7 genuine decisions the walk-through made (3 dismissals, 1 reopen, 1 contacted, 1 reviewed, 1 promotion) there were exactly **7 `signals.opportunity.*` events, none duplicated**, with the key `type:{tenant}:{opportunity}:{review id}` (for example `signals.opportunity.dismissed:6:94:14`) and the payload `opportunity_id, review_id, decision, reason_code, from_status, to_status`. The only history rows without an event were the two my seed script inserted directly. Promotion also recorded `crm.lead.created:6:622`. No row in `g2g_event_delivery` yet: the projector runs from the scheduler, which dev does not run (same as every other event type).

## Found and fixed during the walk-through

1. **Run 1 had 9 failures, all in my script's timing**, not the screen: facet checks read the list before it had refetched. The drawer, dialog, filtered-empty and the other waits passed in run 1. Fixed the script to wait for the address and for the list to settle, then re-ran from a clean seed (run 2: 71/71). The Runs failure in run 1 was a wrong selector (the block is a labelled region, not a group); the screenshot showed the tab rendering correctly.
2. **Wording** (found by looking at the screens): an unassigned signal's product chip read "None" (now "No product"); runs made before runs recorded a Product Master id showed the raw key `scholarclone` (now named from the Product Master key, top-level products only, case-insensitive). g2gv0 commit `c853599`, 190 front-end tests, the new fallback's mutation caught.
3. A first phone screenshot looked empty; it was the slide-in animation. A proper phone check (wait, then measure) passed 7/7. The round "N" over the Contacted button in screenshots is Next.js's dev badge, not part of the product.
4. **Cleanup helper gap:** promotion creates a lead and a `crm.lead.created` event; the helper now removes both.

## Cleanup and baseline (verified)

Test data removed (10 history rows, 1 lead, 9 signals, 9 companies, 2 runs, their events); the minted token deleted; both servers stopped, ports free. Against the baseline taken before the first write: organisation 6 has 48 signals, 3 history rows (all `via backfill`), no `S2 CHECK` rows anywhere, 0 `signals.opportunity.*` events, no lead 622, no `pm-walkthrough` token; real signal statuses unchanged (Dismissed 2, Follow-up 1, New 45).

## Not verified

- **A user without the Signals edit right getting 403** in the browser: no second session was minted (a second user's token can be refused by the auto-mode classifier). Covered only by the PHP test that reads the route middleware and the menu-rights test (track A).
- Keyboard-only use and a screen reader, and focus returning after the dialog closes (jsdom only).
- Anything on live or on MariaDB 10.1 beyond the read-only date SQL probe. Concurrent deciders (`lockForUpdate` does nothing on sqlite; MariaDB behaviour is untested).
- The sidebar highlighting of the new leaf was seen (the Signals section is active in the screenshots) but the leaf's own label was not read in the menu.
