# WPD test map: every test of the deleted Products and Services screens, and where its behaviour is pinned now (23 Nov 2026)

Track S15-WPD, g2gv0 `s15-finish` (HEAD `529b741`, clean tree at the start). Method of `p1c-test-map-2026-11-13.md`. Written BEFORE any deletion; the names of the replacing tests were fixed here first and written to those names. Counts are filled in at the end (section 6).

**Baseline (verified, before any change).** `tsc` 0 errors. Whole suite (the `npm test` glob: `lib/**/*.test.ts`, `lib/**/*.test.tsx`, `packages/**/*.test.ts`): **3,764 tests, 684 suites, 3,764 pass, 0 fail**; 223 test files under `lib` and `packages`.

**Legend.** R = still pinned by a test that stays (named, with its file). N = nothing that stays pinned it, so I WROTE one in a file that stays (named; each has a mutation proof in `wpd-mutations-2026-11-23.md`). M = the test is moved to the code that stays (same assertions, retargeted). D = the pinned code is deleted and the behaviour goes with it (reason given; the parity row of `wpd-parity-checklist-2026-11-23.md` is named when the behaviour was dropped on purpose). X = the behaviour is not carried and no passing replacement can exist (none in this map).

Short file names of the surviving tests: `PLS` = `lib/portfolio-list/portfolio-list-screen.test.tsx`; `PLA` = `lib/portfolio-list/portfolio-list-actions.test.tsx`; `PLM` = `lib/portfolio-list/model.test.ts`; `PLQ` = `lib/portfolio-list/query.test.ts`; `SPP` = `lib/portfolio/sub-product-page.test.tsx`; `PP` = `lib/portfolio/product-page.test.tsx`; `PFD` = `lib/portfolio/product-form-drawer.test.tsx`; `FM` = `lib/portfolio/form-model.test.ts`; `PR` = `lib/portfolio-redirect.test.ts`; `PRS` = `lib/portfolio-redirect-screen.test.tsx`; `PF` = `lib/scaffolds/page-frames.test.tsx` (the shared DetailPage and list frames). `CARRIED-PAGE` = `lib/portfolio/sub-product-page-carried.test.tsx` (new); `CARRIED-LIST` = `lib/portfolio/list-carried.test.tsx` (new); `CARRIED-FM` = `lib/portfolio/form-model-carried.test.ts` (new); `PM` = `lib/portfolio/page-model.test.ts` (new); `TONES` = `lib/product-master/tones.test.ts` (new).

## 1. The tests I wrote or moved (all stay)

**`CARRIED-PAGE`** (13, real page in a fake browser, the services of `lib/portfolio/screen-kit.ts` faked): 1 "says so and asks the server nothing when there is no session" / 2 "shows the server's error with a Retry that reads the record again" / 3 "keeps the page and says what failed when the usage cannot be read" / 4 "says it is in no price book yet, and what a quote uses instead" / 5 "draws a real zero in the usage counts as 0" / 6 "says Signals has no value for a record that was not counted, and draws a real zero as 0" / 7 "keeps the record that was asked for last when the answer for the one before comes late" / 8 "says what the server answered when archiving is refused, and the record stays as it was" / 9 "restores an archived record without asking" / 10 "offers Archive and Restore only to a caller whose rights allow them" / 11 "reads the record again after a save in the form, and the new name reaches the heading and the breadcrumb" / 12 "writes the tab to the address when the person changes it, and leaves the plain address on the Overview" / 13 "opens the Usage of a catalogue-only record, and says on its Signals & ICP tab that it has no About yet, asking nothing".

**`CARRIED-LIST`** (4, the real list of `lib/portfolio-list/screen-setup.ts`; WPC's component, so the tests are in my own file and their files are untouched): 12 "draws a real zero of signals as 0 and a missing count as a dash" / 13 "marks an archived Sub-product, an archived Product and a shared Product" / 14 "shows the answer to the newest search, not a slower answer to an older one" / 15 "gives every ideal-customer state of the list its own word and tone".

**`CARRIED-FM`** (2): 16 "sends text that is not a number as the text, so the server can say so" / 17 "accepts an ID of 50 characters and refuses one of 51".

**`PM`** (3): "says every ideal-customer state in words, and Not applicable when none applies" (written: N) and, MOVED, "opens Company Opportunities for a Product, and for one of its Sub-products" and "has no Signals address for a record with no Product key" (from `lines-model.test.ts`; `signalsHref` moved from the deleted `product-lines-model.ts` to `lib/portfolio/page-model.ts`, its one live caller is `sub-product-tabs.tsx`). `TONES` (1): "draws every readiness state in its colour" (from `model.test.ts`; `readinessTone` moved from the deleted `product-master-model.ts` to `lib/product-master/tones.ts`, its live caller is `portfolio-chips.tsx`).

**Guards (changed, not removed).** `lib/portfolio-address-gone.test.ts` (6 + 2 tests): every allowance for a deleted file removed; one test added, "the old Products and Services components and libs are gone from disk" (a resurrected file fails it). `lib/product-master/contrast.test.ts`: the 4 pairs of the deleted scope switch leave the contrast table (8 tests, section 3), the scan of the 9 deleted product-line components (2 tests) is replaced by one test that none of them is back.

## 2. Tests removed, file by file

### 2.1 `lib/product-master/lines-panels.test.tsx` (19 tests, file deleted: the Signals & ICP and Usage panels of the old catalogue page)
| # | Test (shortened) | Now |
|---|---|---|
| 1 | shows nothing, and asks nothing, on a tab that is not one of the extra tabs | D: `extraTabs` was the old catalogue page's rule; the tabs are `subProductTabs` now. R for "nothing is read before a tab opens": SPP "Usage: reads only when opened ..." and "History: reads only when opened ..." |
| 2 | shows nothing, and asks nothing, for an item with no record behind it | D (an item with no master record at all has no page: PRS "says 'could not be found' for an id that is not this organisation's or no longer exists (404), and offers the Portfolio"; PR "goes to the Sub-product page for a Sub-product, and for a catalogue-only record" for one that has a record) |
| 3 | never opens Signals & ICP for a catalogue-only item, but does open its Usage | N: CARRIED-PAGE 13 (a catalogue-only record opens its Usage; its Signals & ICP says it has no About yet and asks the Portfolio nothing) |
| 4 | opens neither extra tab for a product line, which has no catalogue item | R: PP "has three tabs: Overview, Sub-products and Signals & ICP" (a Product has no Usage tab) |
| 5 | opens Usage for an item tied to a sub-product, asking only for the usage | R: SPP "Usage: reads only when opened, counts every kind of use and links the latest quotes, orders and invoices to their pages" |
| 6 | Signals & ICP: reads the sub-product, its usage, the rights and the choices, and shows who it is, what Signals found and the ICP | R: SPP "Signals & ICP: the signals found and the ideal customer state come from the list and the record, never from usage" |
| 7 | shows Loading… before the answer arrives | R: PF "shows an opening state, with no record, while loading" (the page draws through `DetailPage`) |
| 8 | shows the error in place of the tab when the sub-product cannot be read | R: PF "shows the error with Retry and a way back"; N: CARRIED-PAGE 2 pins the page's wiring of a failed read to it |
| 9 | keeps who the sub-product is on screen, and says what failed, when only the usage cannot be read | N: CARRIED-PAGE 3 |
| 10 | says so, and asks nothing, when there is no session | N: CARRIED-PAGE 1 |
| 11 | offers "Edit sub-product details" to someone who may edit a sub-product, and to no one else | R: SPP "hides Edit when the caller may not edit, and for a shared record"; "opens the one form on Edit" |
| 12 | opens the sub-product's form WITHOUT its Sell in CRM section | D: there is one form with three sections (the Sell section shows for a person who may see it); PFD "shows HR the About and the Ideal customer only: no Sell section, no price anywhere" |
| 13 | saves only what changed, thanks the person, and tells the page the new name | R: PFD "edits the About alone: only the changed About key is sent, and no Sell"; N: CARRIED-PAGE 11 (the new name reaches the heading) |
| 14 | does not save, and does not rename, when nothing was changed | R: PFD "sends nothing for a record nobody changed: Save is off" |
| 15 | Usage tab: shows the signals, the quotes and the price books of an item that is in the catalogue | R: SPP "Usage: reads only when opened, counts every kind of use ..." (asserts 'Standard 2026', the quote, order and invoice links, the price-books link) |
| 16 | says it is in no price book yet, and what a quote uses instead | N: CARRIED-PAGE 4 |
| 17 | shows the error when the usage cannot be read | N: CARRIED-PAGE 3 |
| 18 | says so, and asks nothing, when there is no session | N: CARRIED-PAGE 1 |
| 19 | reads again for another item, and shows the newer answer, not a late one for the first | N: CARRIED-PAGE 7 |

### 2.2 `lib/product-master/lines-parts.test.tsx` (13 tests, file deleted: the scope switch, the extra columns and the badges of the old catalogue list)
| # | Test (shortened) | Now |
|---|---|---|
| 1-5 | the scope switch: shows the two scopes as a group of buttons and marks the open one as pressed / presses the product lines when they are the open scope / asks for the other scope when it is clicked ... / shows how many product lines there are on their button ... / puts the count on the product lines button only | D: the Catalogue / Product lines switch is gone (owner decision of 13 Nov; parity B1.17) |
| 6 | the extra columns: has three headings: the product line, the Signals found and the ICP | R: PLS "draws every column of mock-up view 2 for an administrator, plus Category and In stock (hidden below the large breakpoint)" (Signals and ICP are headings; the product line is the group header) |
| 7 | shows the product line as a link to its page, the signals count and the ICP state | R: PLS "shows the Products as group headers with their Sub-products under them ..." and "opens a Sub-product on its own page and a Product on /portfolio/{key}"; "shows a row's own values: ... signals and ICP, all in words" |
| 8 | does not let a click on the product line also open the item behind it | D: no product-line column in a row; the Product is a header with its own link |
| 9 | shows dashes for an item that is tied to no sub-product, and a 0 for a real zero | N: CARRIED-LIST 12 |
| 10 | tolerates an item from an older server that sends none of the three fields | D: older-server shape of the deleted columns; the new list reads `/product-master/list`, which always sends them (PLS "says the portfolio is not set up on a server that has no Product database" covers a server without it) |
| 11 | the ICP badge: words every state and explains it in a tooltip | N (the words): `PM` "says every ideal-customer state in words, and Not applicable when none applies" (the page's `icpStateLabel`) and CARRIED-LIST 15 (the list's `ICP_WORD`); R (one state each, in the screens): SPP "Usage: ..." and PP show 'In use'; D (the tooltip: parity B4.1y) |
| 12 | draws a dash, with no tooltip, when no ICP applies | N: CARRIED-LIST 12 (the dash / "Not applicable" cell) |
| 13 | draws a count, a real zero, and a dash that says Signals does not look for the record | N: CARRIED-LIST 12; D (the tooltip sentence) |

### 2.3 `lib/product-master/lines-record-page.test.tsx` (32 tests, file deleted: the old Product line page and the S1 Sub-product page)
| # | Test (shortened) | Now |
|---|---|---|
| 1 | a sub-product not in the catalogue: shows its name, where it sits, and the four tabs, opening on the Overview | R: SPP "shows its name, where it sits and the six tabs of both old modules, opening on the Overview" |
| 2 | shows its identity and what the Portfolio says about it on the Overview | R: SPP "Overview: the whole About, the Product as a link, and nothing from Sell" |
| 3 | goes back to the Products screen when the back button is pressed | R: SPP "says it cannot be found for an unknown record, and goes back" (`onBack` wiring); the destination is the page file `app/portfolio/products/[id]/page.tsx` |
| 4 | says what Signals found and how its ideal-customer profile stands, with a way to each, on the Signals & ICP tab | R: SPP "Signals & ICP: the signals found and the ideal customer state come from the list and the record, never from usage" |
| 5 | says no ICP applies, links nowhere, when the server says so, and "Not counted" when Signals has no value | N: CARRIED-PAGE 6 |
| 6 | shows a real zero as 0 | N: CARRIED-PAGE 6 |
| 7 | offers Sell in CRM on the Sell tab to someone who may add to the catalogue, and not to someone who may not | D: no Sell tab and no "Sell in CRM" button; the Sell switch is in the one form: PFD "shows a sales-like caller (Sell only) the Sell section on a record that has none yet"; "shows HR the About and the Ideal customer only: no Sell section, no price anywhere" |
| 8 | sells it in the CRM from the Sell tab: the form opens with Sell switched on, saves it as a sale, and the page then shows the catalogue item | R: PFD "creates with a Sell only (a sales person: no About typed)" and "brings a binned record back only when Sell in CRM is switched on"; FM "a row in the Recycle Bin is brought back by switching Sell in CRM on, and not otherwise" |
| 9 | says on the Usage tab that it is not in the catalogue, so it has no quotes or price books | D: a Sub-product with no Sell section has no Usage tab (`subProductTabs`); SPP "has no Bundle tab for a Service, and no Custom Fields or Details tabs for a record with no Sell section" and "shows HR the Overview and Signals & ICP only" |
| 10 | shows a real zero of signals on the Usage tab as 0 | N: CARRIED-PAGE 5 and 6 |
| 11 | opens on the tab the address names | R: SPP "opens the tab named in the address, and moves between tabs with the arrow keys" |
| 12 | writes the tab to the address when it is changed | N: CARRIED-PAGE 12 (the reading of the address stays pinned by SPP "opens the tab named in the address, and moves between tabs with the arrow keys") |
| 13 | a sold sub-product: links to its catalogue page, by type, from the header and the Sell tab | D: there is one page (the catalogue page is the Sub-product page); the old catalogue address redirects (PRS, PR "writes the four targets") |
| 14 | links a sold service to the Services page | D: same |
| 15 | shows its price books, and how many, on the Usage tab | R: SPP "Usage: reads only when opened, counts every kind of use ..." ('Standard 2026', the price-books link, the count row) |
| 16 | says it is in no price book yet, and what a quote uses instead | N: CARRIED-PAGE 4 |
| 17 | a product line: has an Overview and a Signals & ICP tab, and no Sell or Usage tab | R: PP "has three tabs: Overview, Sub-products and Signals & ICP" |
| 18 | lists its sub-products on the Overview, each opening its own page, and offers to add one | R: PP "lists its own Sub-products on the Sub-products tab, each linking to its page, read with the Product's id"; "opens the one form for a new Sub-product under this Product, with the next ID suggested" |
| 19 | offers no Add sub-product on a shared line or to someone who may not add | R: PP "has no Add sub-product button for a caller who may not add one"; "marks a built-in Product as Shared, read only, and offers no archive for it" |
| 20 | opens Company Opportunities for the line, and says an ICP is set per sub-product with the way to the Offers tab | R: PP "keeps the four setup steps, the customer and buyer summary and a plain Review opportunities link"; "lists the ideal customer state of each Sub-product on the Signals & ICP tab, each linking to that Sub-product's tab" |
| 21 | mentions a profile saved on the line earlier only when one was really saved, and never promises it can be read | D: the sentence about a legacy line-level profile is dropped (parity B4.8: nothing reads that profile) |
| 22 | changing a record: asks before archiving, says what stays, and archives only when agreed | R: SPP "asks before archiving, keeps the record when the answer is no, and archives when it is yes" |
| 23 | does not archive when the question is answered Cancel | R: the same SPP test |
| 24 | restores an archived record without asking | N: CARRIED-PAGE 9 |
| 25 | shows what the server says when it refuses to archive, and the record stays as it was | N: CARRIED-PAGE 8 |
| 26 | offers Edit, Archive and Restore only to someone whose rights allow them | R (Edit): SPP "hides Edit when the caller may not edit, and for a shared record"; N (Archive, Restore): CARRIED-PAGE 10 |
| 27 | restores an archived product line only for an administrator, and an archived sub-product for anyone with the edit right | N: CARRIED-PAGE 10 (the page offers what `can.restore` says; the rule itself is the server's, pinned by PHP tests) |
| 28 | offers no editing of a shared sub-product either | R: SPP "hides Edit when the caller may not edit, and for a shared record" |
| 29 | offers no changes to a built-in, shared line | R: PP "marks a built-in Product as Shared, read only, and offers no archive for it" |
| 30 | opening a record that cannot be shown: says it was not found, with a way back, when the server answers 404 | R: SPP "says it cannot be found for an unknown record, and goes back"; PF "says a record was not found, or is not theirs to see, with a way back" |
| 31 | shows the error, and Retry asks again, when the server fails | N: CARRIED-PAGE 2; R: PF "shows the error with Retry and a way back" |
| 32 | says so when there is no session | N: CARRIED-PAGE 1 |

### 2.4 `lib/product-master/lines-view.test.tsx` (25 tests, file deleted: the old Product lines list)
| # | Test (shortened) | Now |
|---|---|---|
| 1 | shows the Products title, the switch it was given, and each product line with the sub-products inside it | R: PLS "is a product page with its own title and NO Signals buttons ..."; "shows the Products as group headers with their Sub-products under them, and a 'No product yet' group for strays" |
| 2 | draws a real zero as 0 and no signals as a dash | N: CARRIED-LIST 12 |
| 3 | says in the ICP column how many of a line's sub-products have an active profile, with the sentence in its tooltip, and a dash for a line with none | D: the per-Product aggregate and the tooltip are dropped (parity B4.1z); each Sub-product's own state is in the ICP column (PLS "shows a row's own values ...") and on the Product's Signals & ICP tab (PP "lists the ideal customer state of each Sub-product ...") |
| 4 | opens a line, and a sub-product that is not sold, on its own page; a sold one opens in its own catalogue, by type | R: PLS "opens a Sub-product on its own page and a Product on /portfolio/{key}" |
| 5 | hides a line's sub-products when it is collapsed and shows them again | D: a group is always open (parity B4.1x) |
| 6 | says a line has no sub-products and offers to add one only where that is allowed | R (the Add button): PLA "opens the form for a new Sub-product, starting under the Product the list is about (the filter), from the toolbar and from a Product's own header"; D (the "no sub-products" sentence: the header says "0 sub-products") |
| 7 | hides the "no sub-products" row of a line while that line is collapsed | D: no collapse, no such row |
| 8 | does not offer to add a sub-product to someone who may not | R: PLA "shows Add product to an administrator only, and Add sub-product to a person who may add a Sub-product" |
| 9 | marks an archived sub-product, and an archived line | N: CARRIED-LIST 13 |
| 10 | explains what the columns mean | D: the column help is dropped; chips carry words (parity B4.1y); PLS "says every word as Product or Sub-product ..." |
| 11 | asks for the active ones with no search to begin with | R: PLS "sends the default view: active records by name, page 1, 20 to a page, the bearer header, no other filter" |
| 12 | reads the search and the status from the address | R: PLS "opens a link as it was written: ?type=service filters to services and shows the choice"; PLQ "opens on the active records by name, 20 to a page, and a clean address", "writes every choice back to the address and drops what is the default" |
| 13 | shows the loading state until the answer arrives, then the list | R: PLS "says it is loading before the first answer arrives" |
| 14 | shows the answer to the newest search, not a slower answer to an older one | N: CARRIED-LIST 14 |
| 15 | shows the error with a Retry that asks again | R: PLS "shows the error with a Retry that asks again" |
| 16 | says so, and asks nothing, when there is no session | D: the new list has no session branch of its own: the page draws it inside `ProtectedLayout` (`app/portfolio/page.tsx`), which holds a signed-out visitor back, and a missing bearer is answered 401 by the server and shown by PLS "shows the error with a Retry that asks again"; PLS "says so when the person has no access (403), without a Retry" |
| 17 | says the product lines are not set up here when the server has no Product Master | R: PLS "says the portfolio is not set up on a server that has no Product database" |
| 18 | says there are no product lines yet, and offers to make one, when there are none | R: PLS "says there is nothing yet (no filter) and offers the Add buttons, or only the words when the caller may add nothing" |
| 19 | does not invite someone who may create nothing to create the first product line | R: the same PLS test |
| 20 | says nothing matches, and offers to clear it, when a search finds nothing | R: PLS "says nothing matches a search or a filter and offers a way out that clears them" |
| 21 | offers New… only when the server says this person may create something | R: PLA "shows Add product to an administrator only, and Add sub-product to a person who may add a Sub-product" |
| 22 | the New… menu: offers a product line and a sub-product, and never a catalogue item | R: the same PLA test (two buttons replace the menu); D (the menu) |
| 23 | offers only a sub-product to someone who may not create a product line | R: the same PLA test |
| 24 | creates a product line, says so, and offers to add its first sub-product with that line already chosen | R: PLA "opens the form for a new Product from Add product, and reads the list again after it is saved" and "... from a Product's own header" (the Product's header carries "Add sub-product"); D (the immediate offer after the create: a notice "Saved." and the header button instead) |
| 25 | adding a sub-product: opens the form on that product line from "Add one" under an empty line, and saves what was typed | R: PLA "opens the form for a new Sub-product, starting under the Product the list is about (the filter), from the toolbar and from a Product's own header"; PFD "opens under a Product with its next ID suggested, and three sections" |

### 2.5 `lib/product-master/product-detail-tabs.test.tsx` (13 tests, file deleted: the tabs of the old catalogue item page)
| # | Test (shortened) | Now |
|---|---|---|
| 1 | the tabs are exactly the old ones for an item with no record behind it, and the product lines are never asked for | D: no catalogue page without a record (see 2.1 row 2) |
| 2 | ... for an item from an older server that sends none of the new fields | D: older-server shape of the deleted page |
| 3 | gain Signals & ICP and Usage for an item tied to a sub-product, and ask for nothing until one is opened | R: SPP "shows its name, where it sits and the six tabs of both old modules, opening on the Overview"; "Usage: reads only when opened ..."; "History: reads only when opened ..." |
| 4 | gain Usage alone for a catalogue-only item | N: CARRIED-PAGE 13 |
| 5 | leave a service without a Bundle tab, and still gain the extra ones | R: SPP "has no Bundle tab for a Service, and no Custom Fields or Details tabs for a record with no Sell section" |
| 6 | show what Signals found, the ICP and the sub-product when Signals & ICP is opened | R: SPP "Signals & ICP: the signals found and the ideal customer state ..."; "Signals & ICP: names the three ideal customer models and opens the form to edit them" |
| 7 | show the signals, quotes and price books when Usage is opened, asking only for the usage | R: SPP "Usage: reads only when opened, counts every kind of use ..." |
| 8 | link the sub-product's product line to its own page under Products | R: SPP "Overview: the whole About, the Product as a link, and nothing from Sell" |
| 9 | go back to Details without asking again, and ask again when the tab is reopened | D: the reread-on-reopen rule belonged to the deleted lazy panels; the new page reads each tab when opened (SPP "Usage: reads only when opened ...") and again after a save |
| 10 | offer "Edit sub-product details" on Signals & ICP, and the form leaves out Sell in CRM | R: SPP "Signals & ICP: names the three ideal customer models and opens the form to edit them"; D (the form without Sell: one form) |
| 11 | follow a rename made from Signals & ICP in the page's heading, without reading the item again | N: CARRIED-PAGE 11 (the new page reads the record again; the heading and breadcrumb follow) |
| 12 | offer no editing of the sub-product to someone who may not edit one | R: SPP "hides Edit when the caller may not edit, and for a shared record" |
| 13 | keep the item's own Edit button, which opens the CRM dialog as before | R: SPP "opens the one form on Edit" |

### 2.6 `lib/product-master/products-screen.test.tsx` (17 tests, file deleted: the old Products and Services list screens)
| # | Test (shortened) | Now |
|---|---|---|
| 1 | the catalogue as it has always been: looks exactly as before on a server that has no product lines: no switch, no extra columns | D: the old catalogue list is gone; the one list is PLS "draws every column of mock-up view 2 ..." |
| 2 | shows the switch, but no extra columns, when the product lines exist and no item is tied to one | D: the switch and conditional columns are gone |
| 3 | shows the Product line, Signals and ICP columns when an item is tied to a sub-product | R: PLS "draws every column of mock-up view 2 for an administrator, plus Category and In stock ..." |
| 4 | still opens an item's page when its row is clicked | R: PLS "opens a Sub-product on its own page and a Product on /portfolio/{key}" |
| 5 | says, when the catalogue is empty, that the product lines are in the other view | D: no other view |
| 6 | makes the empty row span the whole table, with or without the extra columns | D: the empty state is a card, not a table row (PLS "says there is nothing yet ...") |
| 7 | says nothing about the other view when the catalogue is empty and there are no product lines | D: no other view |
| 8 | moving between the catalogue and the product lines: opens the product lines, and writes that to the address, when their button is pressed | D: no switch (parity B1.17) |
| 9 | goes back to the catalogue, and clears the address, when the Catalogue button is pressed | D: no switch |
| 10 | opens straight on the product lines from a link, with the way back in place | D: the old `?scope=lines` link lands on the one list with its query kept: PR "keeps the query exactly, in its order, with a repeated key repeated and a space as %20" |
| 11 | opens each sub-product where it belongs: a sold one in its catalogue, an unsold one on its own page | R: PLS "opens a Sub-product on its own page and a Product on /portfolio/{key}" |
| 12 | treats an unknown scope in the address as the catalogue | R: PLQ "falls back to the default for a value the screen does not know (a link cannot send the server nonsense)" |
| 13 | says the product lines are not set up when the server has none, and still offers the way back | R: PLS "says the portfolio is not set up on a server that has no Product database" |
| 14 | the catalogue list on its own: points at the other view only where that view's switch is drawn | D: no other view |
| 15 | never points a service list at the product lines, even if it were given a switch | D: no other view |
| 16 | the Services screen: never shows the scope switch or the other view's hint | D: no switch; the Services address redirects to the Type filter: PR "opens the list on Services (type=service) as a temporary redirect, and keeps the rest of the query after it" |
| 17 | shows the three extra columns for a service tied to a sub-product, like the Products screen | R: PLS "shows a row's own values: type, SKU, price, state, readiness, signals and ICP, all in words" ('ScholarClone Implementation': Service, SVC-1, Paused) |

### 2.7 `lib/product-master/lines-model.test.ts` (34 tests; the file is deleted with `product-lines-model.ts`, 2 tests move to `PM`)
| # | Test group (names shortened) | Now |
|---|---|---|
| 1-3 | which scope the Products screen is in: reads the scope from the address / keeps the scope and the status filter in the address ... / names the two scopes | D: no scope (parity B1.17); the address keeps the filters: PLQ "writes every choice back to the address and drops what is the default" |
| 4-8 | the scope switch: is shown on the Products screen where the product lines exist / never on the Services screen / hidden where the lines do not exist / stays while the lines are open / shown when the catalogue is empty but the lines are not | D: no switch |
| 9-12 | the extra columns: shown only when at least one item is tied to a sub-product / make the rows span the table as wide as the table / say what the other view holds when the catalogue is empty / say nothing when there is nothing to point to | D: no conditional columns, no other view; PLS "draws every column ..." |
| 13-15 | the state of an ICP profile: has a word for every state and none when no ICP applies / has a sentence for every state / has its own colour | N (words): `PM` "says every ideal-customer state in words, and Not applicable when none applies" and CARRIED-LIST 15; N (colour: in use good, a draft pending, archived quiet, built-in informational): CARRIED-LIST 15; D (the per-state sentence: tooltip text, parity B4.1y) |
| 16-19 | the ICP column of a product line: counts the sub-products that have an ACTIVE profile out of all of them / counts nothing else as set up / speaks in the singular for one / is a dash with its reason for a line with no sub-products | D: the per-Product aggregate is dropped (parity B4.1z) |
| 20-23 | the extra tabs of a catalogue item: gives an item tied to a sub-product both tabs, in this order / ... the Usage tab alone ... / ... nothing / names the tabs | D: `extraTabs` is deleted; R for the tab order and names: SPP "shows its name, where it sits and the six tabs of both old modules, opening on the Overview" |
| 24-28 | the tabs of a catalogue item's page: exactly the old tabs for an item with no record / gain Signals & ICP and Usage, after the bundle and before the history / gain Usage alone / gain nothing when the record has a kind but no id / word every tab | D: `detailTabs` is deleted; R: SPP tabs test (above) and "has no Bundle tab for a Service, and no Custom Fields or Details tabs for a record with no Sell section" |
| 29-31 | the links between the pages: opens a catalogue item in Products or in Services, by its type / opens a product line, or a sub-product that is not in the catalogue, under Products / opens the Products screen on its product lines | D: the old addresses are only redirects (PR "writes the four targets"); the new links are pinned by PLS "opens a Sub-product on its own page and a Product on /portfolio/{key}" |
| 32 | opens Company Opportunities for a product line, and for one of its sub-products | M: `PM` "opens Company Opportunities for a Product, and for one of its Sub-products" |
| 33 | has no Signals address for a record with no product line key | M: `PM` "has no Signals address for a record with no Product key" |
| 34 | the summary under the title of the product lines: counts the sub-products and the product lines, in the singular where it is one | D: the summary line is dropped (parity B4.1y) |

### 2.8 `lib/product-master/model.test.ts` (33 tests; the file is deleted with `product-master-model.ts`, 1 test moves to `TONES`)
| # | Test group (names shortened) | Now |
|---|---|---|
| 1-6 | the form for a new record and for an existing one: starts empty, with Sell switched on only for a catalogue item / can start with the product chosen and a given item type / is filled from what a sub-product already holds / shows Sell as off when the sub-product is not in the catalogue / keeps a product's key, and a catalogue item is always selling / writes a missing price as blank, not as "null" | D: the S1 form state (`emptyForm`, `formFromRecord`) belonged to the deleted `product-master-form.tsx`; R for the same behaviour in the form that stays: PFD "opens under a Product with its next ID suggested, and three sections"; "fills every field of the record, and an ID that cannot be changed"; FM "files every server key under its section" and the create/edit groups (`valuesFromRecord`) |
| 7 | splitLines: one entry per line, trimmed, without blank lines | R: FM "sends every field of the About, each under the key the server reads, with the lists as lists" |
| 8-9 | the Sell part of a request: sends a blank field as null and trims the rest / sends numbers as numbers, and keeps text that is not a number so the server can say so | R: FM "sends EVERY Sell field of the old Products form, each under the key the server reads" and "a field the person emptied is cleared (null), except the person it is assigned to"; N (text that is not a number): CARRIED-FM 16 |
| 10-13 | creating: a product sends only its name and key / a catalogue item always sends its Sell part / a sub-product sends its identity and About, and Sell only when switched on / a sub-product with no product chosen sends null, so the server says which field is missing | R: PFD "adds a Product with a name and a key only, as an administrator"; FM "sends an About only ...", "sends a Sell only ...", "sends both sections together ..."; D (a catalogue item always sends Sell: the concept is gone, a Sell-only create is FM "sends a Sell only: no About keys at all, so the server makes a Sell-only record"); the missing Product is now checked before the server: FM "needs a name, and for a new About a Product, an ID and a readiness" |
| 14-22 | saving changes: sends nothing when nothing changed / sends only the fields that changed / clears the About text when it is emptied / sends the whole Sell part when any of it changed / notices a change to any single Sell field ... / switches Sell on for a sub-product that was not in the catalogue / never sends Sell when it is off / a catalogue item sends its name and Sell, and nothing about a Portfolio offer it does not have / a product can change nothing here | R: FM "sends nothing when nothing changed", "sends only the About keys that changed, never the ID, and not Sell", "sends only the Sell fields that changed, and the one switch as isActive, never enabled", "a field the person emptied is cleared (null) ...", "a row in the Recycle Bin is brought back by switching Sell in CRM on, and not otherwise", "adds an About to a record that has none ...", "renames and moves under another Product only when they changed"; D (the whole-Sell-part rule: the new form sends only what changed, by design) |
| 23-25 | has the form been changed: false for the form as it started / true as soon as something is typed or switched / false again when a change is undone | R: FM "is dirty after a change, an ICP change or a picked picture, and clean otherwise" |
| 26 | keeps a sub-product's key within 50 characters | N: CARRIED-FM 17 (the new rule: an ID of 1 to 50 characters; the old "key within 50 with the Product's key in front" is gone with the S1 key format) |
| 27 | draws every readiness state in its colour | M: `TONES` "draws every readiness state in its colour" |
| 28 | offers "New…" only what the server says this person may create, and never a catalogue item | D: the New… menu is two buttons (PLA "shows Add product to an administrator only, and Add sub-product to a person who may add a Sub-product") |
| 29 | lists the item types a person may add, for a new item and for switching Sell on | D: `addableItemTypes` is deleted; the form's type choice follows `meta.can` (PFD "a Service has no vendor, stock, demand, reorder level or weight") |
| 30 | picks only products for the "Product" choice | R: PFD "opens under a Product with its next ID suggested, and three sections" (the choice lists Products) |
| 31 | shows a price with its currency, and a dash when there is none | R: PLM "writes money with its currency and an Indian grouping, and nothing for a missing amount"; SPP "Details: every Sell field of the old Products page, the picture beside the name and who it is assigned to" |
| 32 | names the kinds: a top-level group is a product LINE, because a "product" on the Products screen is something the CRM sells | D: that vocabulary is banned now (Catalogue, Offer, Item, Product line): PLS "says every word as Product or Sub-product ..."; SPP and PFD carry the same scan |
| 33 | names every field a server error can be keyed by | R: FM "files every server key under its section" |

## 3. Tests changed inside files that stay
| File | Change | Count effect |
|---|---|---|
| `lib/product-master/contrast.test.ts` | The 4 rows of `TEXT_ON_SURFACE` for the deleted scope switch (active side, idle side, idle on hover, count; each tested in light and dark = 8 tests) are removed from `tones.ts` with the constants `SWITCH_ACTIVE`, `SWITCH_IDLE`, `SWITCH_COUNT` (D: the switch is gone; the contrast of every chip, notice and link the live screens use is still computed there and in `lib/portfolio/contrast.test.ts`, `lib/portfolio-list/contrast.test.ts`). In "gives every tone ... a chip that is one of the checked looks" the line about `SWITCH_ACTIVE` is removed (the test stays). The 2 scan tests of the 9 deleted product-line components ("uses no status colour as TEXT ..." and "uses no shared Badge or StatusBadge ...", D: the files are gone; the same two rules are applied to the live files by `lib/portfolio/contrast.test.ts` and `lib/portfolio-list/contrast.test.ts`) are replaced by 1 test: "finds no component of the retired Products and Services screens, so none is left to scan or to import". | 29 to 20 (-8 -2 +1) |
| `lib/portfolio-address-gone.test.ts` | 11 allowances for deleted files removed; the expected-files list loses `product-catalog-list-view.tsx` (and gains a live file); 1 test added: "the old Products and Services components and libs are gone from disk". | 8 to 9 |
| `lib/product-master/tones.ts` (not a test) | `readinessTone` and `ReadinessTone` added (moved), 3 switch constants removed. | |

## 4. Mutation targets (proofs in `wpd-mutations-2026-11-23.md`)
Each of the 11 + 3 + 2 + 2 + 1 new tests and the 2 guard changes gets at least one mutation (a line broken in a COPY of the tree, a named test fails, the copy compared with the original). The mutation runner proves a green baseline first.

## 5. What was found while mapping
See the report: the old Portfolio view and four dialogs are unreachable too and are pinned only by source-text tests (not in this track's set); the per-Product ICP aggregate, the group collapse, saved views and the column tooltips are dropped; `lib/portfolio-list/model.ts` holds a second copy of `readinessTone`.

## 6. Before and after (verified, final run of 23 Nov 2026)

| | files (lib + packages) | tests | suites | pass | fail |
|---|---|---|---|---|---|
| Before (HEAD `529b741`, the live tree at the start; re-run on a pristine `git archive` of HEAD, see the end) | 222 | 3,764 | 684 | 3,764 | 0 |
| After (the live tree, with track WPC's uncommitted work in it) | 224 | 3,722 | 670 | 3,722 | 0 |

Reconciliation, exact: my part is -171 tests: **-186** (the 8 deleted files: 19 + 13 + 32 + 25 + 13 + 17 + 34 + 33), **-9** in `lib/product-master/contrast.test.ts` (29 to 20), **+1** in the address guard (8 to 9), **+23** new (13 + 4 + 2 + 3 + 1); 3,764 - 171 = 3,593. Track WPC's part is +129: its 4 modified test files went from 77 to 84 tests (`lib/portfolio-list/model.test.ts` 22 to 24, `portfolio-list-actions.test.tsx` 23 to 25, `lib/product-picker/consumer-forms.test.tsx` 8 to 10, `picker-screen.test.tsx` 24 to 25) and its 5 new test files hold 122 (`portfolio-list-simplicity` 22, `portfolio-matches-real` 14, `product-picker/contrast` 39, `product-picker/guard` 31, `product-picker/level-one` 16), so 206 - 77 = 129; 3,593 + 129 = 3,722. The number will move again as WPC goes on; the account of THIS track is the -171.

Files: 8 deleted test files (`lines-panels`, `lines-parts`, `lines-record-page`, `lines-view`, `product-detail-tabs`, `products-screen`, `lines-model`, `model`), 5 new (`lib/portfolio/sub-product-page-carried.test.tsx`, `lib/portfolio/list-carried.test.tsx`, `lib/portfolio/form-model-carried.test.ts`, `lib/portfolio/page-model.test.ts`, `lib/product-master/tones.test.ts`); the removed set equals the 186 rows of section 2 and the added set is the 23 of section 1 (3 moved with their old names, 20 written: N).

Every one of the 186 rows carries one of R, N, M or D in section 2 (a row that covers several tests of a group says which of them are which); no row is marked X. Not run: a real-browser pass (the six tasks of the plan, 390 px, axe colour) and a real `next build`.
