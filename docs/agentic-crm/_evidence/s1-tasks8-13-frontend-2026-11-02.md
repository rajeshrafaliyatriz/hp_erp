# S1 tasks 8 to 13: the screens (g2gv0) and the browser check — 2026-11-02

Everything below is **verified** (command run, output read) unless marked. The front end is in the g2gv0 repository (branch `milan1`, local commits, not pushed); the backend additions are in hp_erp.

## What was built

| Repository | Commit | What |
|---|---|---|
| hp_erp | `879b4824` | `GET /api/product-master/meta` (the choices a form offers and what the caller may do) and the offer's About text, `readinessConfirmed` and the catalogue item's GL account in a record. 35 write tests; 21 mutations of the new code, 3 gaps found and closed. |
| hp_erp | `ad2dd429` | `meta` separates who may restore (the screen's edit right) from who may archive (its delete right). 36 write tests (251 assertions); 5 more mutations, all caught. |
| g2gv0 | `fed8775` | The scaffolds in `components/shared/scaffolds/` (tasks 8 and 9). |
| g2gv0 | `047c928` | The Product Master screen (tasks 10 to 12): service and types, the model, the list, the record page, the drawer form, the routes and the content-map entry. |
| g2gv0 | `54bc01e` | Follow-ups from the browser check: Restore uses the restore flags; only the newest response may change the list or the record page; a just-created product is in the picker at once; no "0 sub-products in 0 products" subtitle. |

### Why the backend got a small addition first

Building the form showed the read API did not give it three things the server already owns: the offer's About text (only five offer fields were returned), the GL account, and the valid choices. `meta` returns the readiness states from the same list the writer validates against (`ProductWriter::readinessStatuses`), the item types, this organisation's active tax rates (only to someone who can write the CRM catalogue) and a set of `can` flags worked out by the same `ProductAccess` methods the writer enforces. The screen hides what the server would refuse; the server still refuses it.

### The scaffolds (task 8 and 9), `components/shared/scaffolds/`

Each one composes what `components/ui` already has; **`components/ui` is untouched** (`git diff --stat components/ui` is empty).

- `ListPage` + `useListQuery` + `ListSearch`: the frame of a list screen (loading, error, empty, ready) and a list state (search, sort, page, filters) kept in the URL. The URL rules are pure functions in `list-query.ts`: a hostile value falls back to its default; a change of search, sort or filter goes back to page 1; defaults are left out of the URL; parameters it does not own (`?tab=`) are kept.
- `DetailPage` + `PageTabs`/`TabPanel` + `useUrlTab`: a record page frame and tabs that follow the WAI-ARIA pattern (one tab in the tab order, arrow keys, Home and End, each tab linked to its panel, the arrow keys cancelled so the page does not scroll), with the open tab in the URL.
- `Field`, `NativeSelect`, `FormSection`, `FormLayout`, `FormErrorSummary`, `splitFieldErrors`: a labelled field that ties its label, hint and error to the control, and a split of the server's field-keyed errors into those shown next to a field and those shown together at the top. The existing `FormField` in `shared/business` has no error and no wiring, so this is the accessible replacement for new forms (a different name, `Field`, on purpose).
- `RecordDrawer` (on `ui/sheet`): every way of closing it (X, Escape, outside click, a footer Cancel through a function footer) goes through one guard that asks about unsaved changes and refuses while a save is running.
- `useConfirm` (on `ui/confirm-dialog`), in place of `window.confirm`: a promise that settles on every path, including a second question and an unmount.

### The screen (tasks 10 to 12)

- **List** `/module/crm/product-master`: every product with its sub-products (collapsible), readiness, Sold in CRM and signals per product, plus the catalogue-only items. Search and the Active / Archived / All filter are in the URL. "New…" offers only what `meta.can` allows; creating a product offers "Add its first sub-product".
- **Record page** `/module/crm/product-master/[id]`: Overview, Sell in CRM, Prices and Usage tabs (a catalogue item has no Overview and opens on Sell in CRM; a product has Overview and Usage), the open tab in the URL. Edit, Archive (asks first and says what stays) and Restore. Prices is read-only, from the price books that list the item, with the empty state from the approved mock-up.
- **One drawer form** for creating and editing a product, a sub-product or an item. Each section says where it saves. It sends only what changed on an edit, shows the server's own messages next to the fields (no client-side validation), and clears a field's message when it is edited.

Decisions that differ from, or add to, the plan:

1. **Tabs:** the plan said Overview, Sell, Signals, Usage; the approved mock-up added a Prices tab. Signals is folded into Usage (a count only: signals record their product, not their sub-product), and Prices is its own tab. A catalogue item skips Overview.
2. **About fields:** the form edits "What it does" and "Customer problems it solves". "Who it is for" and "Buying events" stay in the Portfolio and are shown read-only with a link, because their option lists live there and a free-text entry would not match the codes Signals matches on. The API can write them; the screen does not offer it yet.
3. **A product cannot be edited here** (renaming is refused by the API because the Portfolio groups by that name), so a product page has no Edit button.
4. **Selling in CRM cannot be switched off** once on (the API refuses unlinking); the form says so and suggests archiving.

## Tests and checks

- **114 front-end tests** run under `node --test` (`lib/scaffolds/`, `lib/product-master/`): the pure logic, and the real Radix Sheet and AlertDialog in a fake browser (`lib/ui-regression/dom-env.ts`).
- **Mutation sweeps** (each rule broken on purpose, one at a time, original restored byte-for-byte): the scaffolds 119 mutations, 118 caught; the one survivor is equivalent (`-?` in a page-number pattern: the next line rejects the same values, so no test could tell). The model 56 of 56 caught. Sweeps found 12 weak tests, all strengthened (for example: a reversed arrow key that ended on the same tab; a count of 0 drawn as bare text; a dialog that was not checked to close after answering; a 0 ms debounce indistinguishable from a real one). The tooling is in `D:\dev\agentic-crm-research\scripts\mutation\` (`run-fe.cjs` and the JSON lists).
- **A repeat of the stuck-page trap**: the repo's own regression test showed overlapping Radix teardowns once left `<body>` at `pointer-events:none`. The drawer and its "discard?" question close at the same moment, so a test now drives exactly that, with and without simulated exit animations, and asserts the page is still clickable (jsdom). The real-browser check is below.
- `tsc --noEmit`: **14 errors before and 14 after**, none in the new files (the 14 are unrelated, in `account-360.tsx`, `crm-ai-assistant.tsx`, `signal-inbox-view.tsx` and others). ESLint: clean on every new and changed file.

## Browser walk-through (task 13)

**Result: the last run passed all 73 checks** (run 7 of 7). The six runs before it stopped early: mostly on mistakes in my script (waiting for data, a wrong role, a label with a decorative star, a wrong query parameter), and once on a real screen problem (finding 1 below), which I fixed. It drove the real screen, in a real Chromium, signed in as a tenant-6 administrator, against the **local Laravel on the dev database** (the routes exist only in this working tree, so the deployed dev server could not serve them). The script is `D:\dev\agentic-crm-research\scripts\browser\s1-product-master-walkthrough.cjs`; its log, 22 screenshots and `results.json` are in `D:\dev\agentic-crm-research\evidence\s1-browser-2026-11-02\` (outside the repo: images).

What it proved, in the order it ran:

- **List:** the real data (25 sub-products in 5 products, "0 of 7", signal counts, the Shared mark); collapse and expand; search and the Active / Archived / All filter go into the URL and come back out of it; "Nothing matches" with a way back; **New… offers a product, a sub-product and a catalogue item to an administrator**.
- **The discard guard, in a real browser:** typing then closing asks "Discard your changes?"; Cancel keeps the text; Discard closes, and afterwards `<body>` is not at `pointer-events:none` and the page still answers clicks (the regression class noted in the repo's own test).
- **Server errors next to the field:** a one-character product name is refused (422) and shown under Name, nothing was created, and editing the field clears the message; an existing sub-product ID is refused (409) under the ID field with everything typed kept.
- **Create:** a product, a sub-product sold in CRM (SKU, base price, currency) and a service. The list shows them, the product row counts "1 of 1", the service sits under "Other catalogue items".
- **Record page:** the right tabs per kind (sub-product: Overview, Sell in CRM, Prices, Usage; product: Overview, Usage; item: Sell in CRM, Prices, Usage and it opens on Sell in CRM); ArrowRight moves tab and puts `?tab=` in the URL; `?tab=usage` opens Usage; an unknown tab falls back to the first; the Prices tab shows the approved empty state with the real base price; **Usage shows a count of 0 as "0", not a dash**.
- **Edit:** product and ID locked, Sell in CRM locked on with the reason, Save off until something changes; after saving, **the Portfolio offer changed (name, readiness, text), readiness is still not confirmed, and the CRM catalogue row was renamed to match.**
- **Archive and restore:** archive asks first and says nothing is deleted; it leaves the default list and shows under Archived; restore works; **archiving a product that still has an active sub-product is refused with the reason**.
- **The other screens see what was created (acceptance check of task 13):** the Portfolio lists the offer under the new product; CRM Products has the sold sub-product and CRM Services the service; the product list the Signals run screen reads (`/api/portfolio/products`) offers the new product line.
- **Isolation and rights:** another organisation's record is a 404, and it cannot be changed or archived either (404, 404); a request with no token is a 401.
- **A 390 px screen:** the page does not scroll sideways and the drawer fits.
- No console errors, no uncaught page errors, and the only failing requests were the three deliberate refusals above.

**What the browser found** (it is why the check was worth doing):

1. **Fixed:** right after creating a product, "Add its first sub-product" opened the drawer with the Product select still showing "Choose a product…" until the choices were read again. The new product is now put in the picker at once.
2. **Fixed:** with nothing to show, the subtitle still said "0 sub-products in 0 products".
3. **Fixed (found by reading the code while the run went on):** a slow answer to an earlier search could overwrite a later one, on the list and on the record page.
4. **Fixed:** the Restore button followed the archive right (delete) while the route needs the edit right; `meta` now has separate `restore` and `restoreProduct` flags.
5. **Not fixed, a note for whoever builds the next drawer:** pressing Escape within roughly half a second of choosing an item from the "New…" menu is swallowed. The menu's content stays mounted for its exit animation and its Radix layer takes the key first (shown with a stack trace; the drawer's handler is never reached). It is shared dropdown behaviour, a person cannot press a key that fast, and the second press works. The walk-through waits for the menu to finish closing, as a person would.
6. Two of my own script mistakes the browser also taught me: a required field's label holds a decorative `*` (hidden from screen readers but still text, so Playwright's exact label match fails) and the app's custom `Select` has `role="combobox"`, not `button`.

**Every record the run created was removed afterwards**, because the API has no hard delete: the run recorded each id it created, and a helper deleted only rows matching a recorded id **and** organisation 6 **and** creator 1604 **and** a "PM Check" name. The counts then matched the baseline taken before the first write (master 25 for organisation 6 and 55 in all, offers 25, CRM catalogue 3 (soft-deleted October test rows, untouched), own product lines 0). Earlier aborted runs created nothing, or were cleaned the same way (two cleanups, each compared with the baseline). The login token used was minted for a dormant administrator (user 1604), expired after 3 hours, and was deleted at the end.

## Not verified

- **A profile without the screen's right, with a real token, in the browser.** I tried to mint a second token (an Employee, user 30) for the 403 check and the auto-mode classifier refused ("identity not given"); I did not work around it. The 403 cases are covered by the PHP tests (an Employee gets 403; an administrator before the grant gets 403; each write needs its own right), and a missing token is 401 live. Worth doing with the owner's own second account.
- **A non-administrator who has the screen's right** (for example HR) was not seen in the browser: only the PHP tests (`meta` flags, and the writer's rights) and the unit tests of "what New… offers" cover "a non-admin sees no product option".
- **Nothing ran on live; nothing is pushed or deployed.** The routes exist only in the local working tree.
- **The owner has not yet reviewed the finished screen** (the design gate approved the mock-up, not this screen).
- **The old screens still show their own data:** an offer or CRM item created in the old Portfolio or CRM Products screens after the backfill is not in the Product Master until the idempotent backfill is run again. See the owner decision in `00-PROGRESS.md`.
- Only 1400 px and 390 px widths and the app's default (light) theme were looked at; dark mode was not.
- Two near-simultaneous creates of the same sub-product ID were not raced (the unique key plus the 409 mapping handle it, untested).
- The three list-state hooks that call the Next router (`useListQuery`, `useUrlTab`, and the search box's URL write) have no unit test of their own; the browser run covers their behaviour (URL in and out, tab in the URL).
