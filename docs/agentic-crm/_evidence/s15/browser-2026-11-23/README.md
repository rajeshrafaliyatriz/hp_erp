# S15 real-browser acceptance, 23 Nov 2026 (dev database, tenant 6; nothing on live)

Tags: verified = a command ran and its output was read; read in source; inferred; unverified.

## Set-up (verified)
- Backend `php artisan serve` on 127.0.0.1:8000 against the DEV database (tenant 6, "Scholar Clone"); front end g2gv0 branch `s15-finish` through `next dev` on localhost:3000; headless Chromium driven by Playwright; accessibility by axe-core from g2gv0's node_modules.
- Two dev accounts, the two the owner named on 23 Nov: the tenant-6 administrator (profile 16) and the employee (profile 17). Two short-lived API tokens were minted for them and DELETED at the end (`deleted token rows: 1` twice). No live host, no live database, no message sent.
- Script: `D:\dev\agentic-crm-research\scripts\browser\s15-unified-walkthrough.cjs` (+ `s15-axe390.cjs`, `s15-explore.cjs`). Test rows are named `ZZ-S15-TEST ...`; each id is recorded as it is created.

## Result
| Run | Checks | Passed | Failed |
|---|---|---|---|
| Run 1 (`walk-run1.log`) | 37 | 36 | 1 (axe at 390 px) + one API 404 logged |
| Run 2, after the fixes below (`walk-run2-after-fixes.log`) | 37 | 36 | 1 (the same axe finding) ; API failures: none for the administrator and none for the employee |

The administrator checks that passed in both runs: A1 the list is "Product Portfolio", exactly three header actions (More, Add product, Add sub-product), no Catalogue / Product lines switch, no "Views", no retired word, the price columns for the administrator, ONE sidebar entry "Product Portfolio" (no Products, no Services), the pair-review banner; A2 filter and search kept in the address; A3 More holds Import CSV, Sync Excel, Export CSV, Find duplicates and Recycle Bin; A4 the one form has About, Sell in CRM, Ideal customer and Save wrote ONE record with About and Sell (sold, price 123.5); A5 the Sub-product page has Overview, Details, Bundle, Usage, History, Signals & ICP and every tab opens without an error; A6 editing the price saves it; A7 Archive moves it to the Archived list and Restore brings it back; A8 `/module/crm/sales/products` -> `/portfolio`, `/services` -> `/portfolio?type=service`, `/signal-review?status=all&q=x` -> `/signals?status=all&q=x`, `/module/crm/sales/products/{crmId}` -> `/portfolio/products/{masterId}`; A10 at 390 px no sideways scroll and the three actions stay reachable. The employee checks: B1 no "Add product" button, the price columns follow the person's own right, B2 the API sends Sell keys only with the right, B3 `/sell` 200 with the right, B4 the tabs follow the right.
The pair-review screen (`/portfolio?view=matches`, read only, nothing decided) opened as administrator with the real dry-run rows (4 rows, no console or API error) and shows the NEW server wording ("Sub-product code", "CRM record(s)"): `pair-review-1400.png`.

## What the walk found, and what was done (verified)
1. **Real defect, fixed:** with the Signals & ICP tab open, archiving a Sub-product made the tab say "The ideal customer could not be read." (the old `GET /portfolio/offers/{id}` answers 404 for an archived Sub-product on purpose: audit G-E1). Now the page does not ask for it when the record is archived and the tab says "This sub-product is archived. Restore it to see or change its ideal customer: nothing is lost." Test added; broken the guard (page) and the sentence (tab): both fail the test; restored byte for byte. Run 2: the 404 is gone.
2. **Cosmetic, fixed:** "Sub-product" wrapped onto two lines in the Type column (`whitespace-nowrap`).
3. **Not ours (verified, same on a page we did not touch):** the two axe findings at 390 px are in the shared app shell: `aria-hidden-focus` on the mobile `<aside aria-label="Primary Navigation">` and `button-name` on the user-menu button (`.py-1`). The same two appear on `/signals`. The rules say not to edit `components/shell`, so they are reported, not changed. Desktop axe: no serious or critical violation on the list, the Sub-product page and the quotes page (3 minor each).
4. **Walk script bug (mine, fixed):** the Product dropdown's options load after the drawer opens, so the script picked from an empty list; it now waits for the options.

## Clean-up (verified)
`s15_browser_cleanup.php delete` removed exactly 3 master rows, 3 About rows and 3 catalogue rows (ids 75-77, 61-63, 72-74; all `ZZ-S15-TEST`, tenant 6, no pointer at them). Counts after = baseline: master tenant 6 34, master all 64, offers tenant 6 32, catalogue 12, signal products 1.
`product-master:check --tenant=6` after the clean-up reports **7 I4 errors, all pre-existing and not from the walk**: Portfolio offers 54-60 (P1, P2, P4, P5, P6, SCH-11, EB; created 22 Oct to 2 Nov by user 28, never given a master row; the baseline already held them: offers 32 = 25 with a master row + these 7). They are the open step P2.7 (join/backfill on dev) and need `product-master:repair` under DB-RULES with a backup: not done here.

## Not run in a browser (unverified, said plainly)
- Picking a Sub-product in a quote, an order and an invoice and saving: it writes quote/order/invoice rows and numbering on the dev database; proved by jsdom screens (`lib/product-picker/*`, 20 consumers) and by the PHP tests that pin the saved lines to the catalogue id. A no-save check that the picker opens in the quote form is the one browser step still open.
- A tenant-6 user without the Products/Services right does not exist on dev (the employee profile holds view rights), so the "no price at all" view could not be shown in a real browser; it is proved by PHP and jsdom tests (HR fixtures).
- 390 px axe finding on the list is the shell's (above); focus order and a real screen-reader pass were not run.
