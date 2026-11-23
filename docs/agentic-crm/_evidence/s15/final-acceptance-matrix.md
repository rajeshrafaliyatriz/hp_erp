# S15 final acceptance matrix (23 Nov 2026, evening): the owner's words and the plan's steps against evidence

Tags: verified = a command ran and its output was read (this session unless a file is named); read in source; inferred; unverified. Nothing here touched live. Dev = the dev database, organisation 6 ("Scholar Clone").

## 1. The owner's statements (13 Nov and 23 Nov)

| # | Statement | Status | Evidence |
|---|---|---|---|
| 1 | Merge "Signal Review" into Market Signals' Company Opportunities and remove it | done | g2gv0 #376, hp_erp `cc9f7cbf` (merged); browser A8: `/signal-review?status=all&q=x` -> `/signals?status=all&q=x` (verified); the old screen's files are gone |
| 2 | ONE Product Portfolio instead of the CRM Products module (Catalogue / Product lines) | done in code, not on the live site | `milan1` `4c66065`: 27 old files and 186 old tests deleted with a test map (`wpd-test-map-2026-11-23.md`); browser A1 (verified, 2 runs): title "Product Portfolio", exactly three header actions (More, Add product, Add sub-product), no Catalogue / Product lines switch, no "Views", ONE sidebar entry |
| 3 | Product (level 1), Sub-product (level 2), a service is a Sub-product of type Service | done | one form (browser A4), the Type filter, vocabulary scan tests (no Catalogue / Offer / Item / Product line in any state of the list: `portfolio-list-simplicity.test.tsx`), Ask words and the pair-review sentences reworded (WPB) |
| 4 | Keep and sync the Products tables | done in code; one dev data step open | `ProductWriter` v2, `ProductLines`, import commands mirror (`PortfolioImportMirrorTest`); `product-master:check --tenant=6` after the walk: 7 errors of kind I4 that were already there (Portfolio offers 54 to 60 never given a master row, created 22 Oct to 2 Nov): the open step P2.7 |
| 5 | Re-point every consumer module ("one, not linked") | done in code and in fake browsers | the shared picker in 20 consumers, `lib/product-picker/*`, `PRODUCT_*` address guard; PHP tests pin the saved lines to the catalogue id; NOT run: saving a quote, an order and an invoice in a real browser (writes numbering on dev) |
| 6 | "Make the UI less confusing" (screenshot of the live Products screen) | done in code | 3-action header, one list, plain state words, filters in one row kept in the address, banner for possible matches, redirects from every old address, no sideways scroll at 390 px (browser A10) |
| 7 | Duplicate pairs: decide later in the new review screen | done | `/portfolio?view=matches` opened as administrator with the real dry-run rows (4 rows), read only, nothing decided (`browser-2026-11-23/pair-review-1400.png`) |
| 8 | Product creation: one door, keep main's fields | done | WPA1 item 1: `POST /api/signals/icp/products` writes through `ProductLines::create` with description, category, logo, status (`ProductOneDoorTest`, 21 tests) |
| 9 | A Product that still has Sub-products is refused on delete (D-9) | done | merged in #580; `ProductDeleteGuardsTest` |
| 10 | An account with no organisation gets 400 with the fixed sentence | done | merged #579 |
| 11 | Old Products and Services screens removed in the SAME release | done in code | `4c66065` (same branch as the new list); the sidebar rows stay `status 1` (hiding wipes rights) |
| 12 | Browser test on dev tenant 6 with the two named accounts, ZZ-S15-TEST rows removed | done | `browser-2026-11-23/README.md`: 36 of 37 checks twice; rows deleted, counts equal the baseline |
| 13 | No new branches; Milan-2 / milan1 only; no push, merge or deploy without the owner's word | kept | hp_erp `Milan-2` holds `origin/main` and is 3 commits ahead of it (the briefs, the backend work, the evidence; the WPA2 commit follows), g2gv0 `milan1` holds `origin/main` and is 10 commits ahead of `origin/milan1`; nothing pushed |

## 2. The plan's steps (P0 to P5)

| Step | Status | Evidence |
|---|---|---|
| P0 decisions, baseline, approved mock-up | done | DECISIONS.md, `p0-*` |
| P1 Signal Review inside Company Opportunities | done (browser step P1.8 now done as A8) | above |
| P2.1 to P2.6 registry, checker, defect fixes, repointer, writer v2, read API, pair review code | done | in main; plus the audit gaps G-1, D-8, D-9, G-E1 |
| P2.7 join the items and Sub-products on dev | NOT done, by decision | the owner chose "decide later in the new review screen"; nothing joins by itself |
| Audit gaps WPA1: D-5 one door, G-D2 imports mirror, G-E2 pointer pattern, D-E1 archive by section, old-form limits | done | WPA1 log, `wpa1-*` evidence, my 12 mutations all killed |
| Audit gaps WPB: G-E4 merged-record alias, D-E4 Product facet finds Sub-products, G-E6 / D-E2 Ask, pair-review wording, Recycle Bin links (finding: no change needed) | done | WPB log, `wpb-*`, my 9 mutations all killed |
| Audit gap D-E5 (a delete of a catalogue item with a master pointer) | decision recorded, nothing built | `wpb-item5-e5-decision-2026-11-23.txt` (dev: 0 binned items, 0 pointers); count on live first |
| WPA2: H2 route map (45 routes) + 10 smoke tests, H3 About guard, W3 host check | done, small tests (route map passes with PARITY_FINAL=1; W3 on dev: 0 drift) | `wpa2-h2-*`, `wpa2-h3w3-*` |
| WPA2: G-D1 schedule `product-master:check` daily; P4.2 `product-master:rights-audit` | done, small tests | `ProductMasterCheckScheduleTest` (2), `ProductMasterRightsAuditCommandTest` (3), the real dev run `rights-audit-dev-tenant6-2026-11-23.txt` |
| P3.2 picker + 20 consumers, P3.3 list, P3.6 pair-review screen, P3.7 redirects, P4.1 sidebar | done | `milan1` commits; 3,726 front-end tests |
| P4.3 remove the old Products and Services screens | done in code | `4c66065`; the five dead old Portfolio dialogs (1,883 lines) are deleted too (WPD2, `b17e105`); two things that lived only in them were restored (Edit Product on the Product page, old `?product=<key>` links), commit `6b25be7` |
| P4.4 retire the Signal Review menu row in data | NOT done, by design | after a soak, on the owner's word |
| P5 browser acceptance | done on dev for admin and employee | `browser-2026-11-23/` |
| P5 docs: DB-CHANGELOG rows 57 and 58, cutover runbook, this matrix, progress block | done | `S15-CUTOVER-RUNBOOK.md` |

## 3. Numbers (verified)

- hp_erp whole PHP suite on the tree with WPA1 and WPB: 4,279 tests, 112,774 assertions, 52 red, 1 warning, 2 skipped. All 52 are explained: 50 are on the known baselines (`sync/main-baseline-failing-tests-2026-11-23.txt` and `sync/milan-2-failing-tests-2026-11-23.txt`: CustomProductApiTest, ProductPortfolioIcpTest, SubProductIcpOwnershipTest, connector tests, ...); 2 outreach tests (`OutreachMailersBaselineTest::test_gtm_refusals_before_the_gate...`, `OutreachMailersGateTest::test_gtm_the_gate_comes_after...`) fail only because this machine's `.env` sets `GTM_OUTREACH_ALLOW_LOCAL_UNSUBSCRIBE`; with it off, both files pass (22 of 22).
- g2gv0 whole front-end suite after the walk's fixes: 3,730 pass, 0 fail (3,726 after the walk, +2 for the old-key redirect and the Product edit, +2 net from the dead-dialog removal); `tsc --noEmit` exit 0; eslint `--max-warnings=0` exit 0 on the changed files; `components/ui` and `components/shell` untouched.
- Mutation proofs of my own: 21 on the backend (every one killed, every file restored byte for byte, `own-mutations-result.txt`), 5 on the front end (archived-ICP guard and sentence, redirect following a merge, merge notice, reworded fixtures), 1 on the rights audit's cache guard. The tracks' own: WPA1 45, WPB 36, WPC 73, WPD (see `wpd-mutations-2026-11-23.md`).
- Real browser: 36 of 37 checks pass in both runs; the one failure is the shared shell's own accessibility finding at 390 px (`aria-hidden-focus` on the mobile sidebar, `button-name` on the user-menu button), identical on `/signals`.

## 4. Not verified, stated plainly

- Which API host the live front end calls and whether hp_erp `main` is deployed there (I cannot see deploys).
- Anything on the live database (never opened), the live Vercel site, MariaDB 10.1 behaviour beyond the static scan (`MariaDb101SafetyTest`).
- Saving a quote, an order and an invoice from the picker in a real browser; the "HR sees no price" view in a real browser (no such tenant-6 user on dev; proved by tests and by the rights audit: HR holds no Products or Services row).
- Focus order and a screen-reader pass.
