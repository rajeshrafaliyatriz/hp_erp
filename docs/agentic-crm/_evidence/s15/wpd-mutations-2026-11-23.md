# WPD mutation proofs (23 Nov 2026)

Track S15-WPD, g2gv0 `s15-finish`. Every new or changed safeguard of this track was broken on purpose in a COPY of the tree (`scratchpad/mut-tree`, a robocopy of g2gv0 with a junction to node_modules). The real tree was never mutated, because the list, the picker and their tests belong to track WPC, who works in the same tree. The runner (`scratchpad/mutate-wpd2.mjs`) first proves a green baseline of each test file, applies ONE edit whose anchor occurs exactly once, runs the named test file with the spec reporter, restores the original bytes and compares them ("restored identical"). A mutation is KILLED when the file reports a failing test; the failing tests are named. Pass 1 ran against the first version of the new tests (page file 11 tests, list file 3, page-model 2); pass 2 ran after I added tests to them (page 13, list 4, page-model 3) and re-ran M09.

Baselines (green first): sub-product-page-carried 11 then 13 tests, list-carried 3 then 4, form-model-carried 2, page-model 2 then 3, tones 1, portfolio-address-gone 9, product-master/contrast 20, portfolio/contrast 38; all 0 fail.

**Result: 51 entries, 51 killed or shown, 0 survivors.** M09 was first reported SURVIVED: its failing assertion compared a DOM element, the report was a file-level failure (33 s) and my parser looked only for named tests. I rewrote the assertion as a boolean check and re-ran M09 in pass 2: killed by the named test. G07 is a diagnostic, not a failing test: it shows the guard's own "no longer needed" line reaches the spec output.

| id | what was broken | file | restored identical | killed by (test names) |
|---|---|---|---|---|
| C01 | a retired product-line component (scope switch) is brought back | `components/domain/crm/product-scope-switch.tsx` | true | finds no component of the retired Products and Services screens, so none is left to scan or to import |
| C02 | a status colour as the text of a Sub-product chip (the live scan of lib/portfolio/contrast.test.ts takes the rule over) | `components/domain/portfolio/portfolio-chips.tsx` | true | uses no status colour as TEXT and no tint of one as a surface; an icon, which is aria-hidden, may be coloured |
| G01 | an old Products address written back into a live component | `components/domain/portfolio/portfolio-chips.tsx` | true | names neither old address anywhere outside the allowances (the redirect pages do not even need one) |
| G02 | an old Services address written back into the Sub-product page | `components/domain/portfolio/sub-product-page.tsx` | true | names neither old address anywhere outside the allowances (the redirect pages do not even need one) |
| G03 | a live route (the Portfolio page) imports an old list component | `app/portfolio/page.tsx` | true | finds none in the pages, the content maps, or the new list, menu, review and redirect |
| G04 | a content map imports the old Products detail page | `hooks/content-map-crm.ts` | true | finds none in the pages, the content maps, or the new list, menu, review and redirect |
| G05 | an old component file is brought back (product-list-view.tsx) | `components/domain/crm/product-list-view.tsx` | true | has none of the old components, models or test kits on disk (a file brought back under an old name would be a  |
| G06 | the old product-master model is brought back | `components/domain/crm/product-master-model.ts` | true | has none of the old components, models or test kits on disk (a file brought back under an old name would be a  |
| G07 (diagnostic) | an allowance for a file that no longer names an address (the diagnostic must say so) | `lib/portfolio-address-gone.test.ts` | true | (none) (diagnostic printed: "ℹ the allowance for lib/utils.ts is no longer needed (a stale allowance used to prove the diagnostic); remove it from the list") |
| M01 | no session: the page no longer checks for a session before asking | `components/domain/portfolio/sub-product-page.tsx` | true | says so and asks the server nothing when there is no session |
| M02 | Retry does nothing | `components/domain/portfolio/sub-product-page.tsx` | true | shows the server's error with a Retry that reads the record again |
| M03 | the server's words are replaced by a fixed sentence when the record cannot be read | `components/domain/portfolio/sub-product-page.tsx` | true | shows the server's error with a Retry that reads the record again |
| M04 | the Usage tab no longer shows a failed usage read | `components/domain/portfolio/sub-product-tabs.tsx` | true | keeps the page and says what failed when the usage cannot be read |
| M05 | a failed usage read also takes the record away (the record is read again on a usage error) | `components/domain/portfolio/sub-product-page.tsx` | true | keeps the page and says what failed when the usage cannot be read |
| M06 | the price-book empty state no longer says what a quote uses | `components/domain/portfolio/sub-product-tabs.tsx` | true | says it is in no price book yet, and what a quote uses instead |
| M07 | an empty base price is no longer said to be empty | `components/domain/portfolio/sub-product-tabs.tsx` | true | says it is in no price book yet, and what a quote uses instead |
| M08 | a count of zero is dropped from the usage counts | `lib/portfolio/page-model.ts` | true | draws a real zero in the usage counts as 0 |
| M09 | a count the server did not send is drawn as a count | `lib/portfolio/page-model.ts` | true | draws a real zero in the usage counts as 0 |
| M10 | a Signals count of zero is said to be "not counted" | `components/domain/portfolio/sub-product-tabs.tsx` | true | says Signals has no value for a record that was not counted, and draws a real zero as 0 |
| M11 | the signals chip hides a zero | `components/domain/portfolio/portfolio-chips.tsx` | true | says Signals has no value for a record that was not counted, and draws a real zero as 0 |
| M12 | a late answer for the record asked first replaces the one asked last (both guards removed) | `components/domain/portfolio/sub-product-page.tsx` | true | keeps the record that was asked for last when the answer for the one before comes late |
| M13 | a refused archive shows a fixed sentence, not the server's words | `components/domain/portfolio/sub-product-page.tsx` | true | says what the server answered when archiving is refused, and the record stays as it was |
| M14 | a refused archive still marks the record as archived | `components/domain/portfolio/sub-product-page.tsx` | true | says what the server answered when archiving is refused, and the record stays as it was |
| M15 | Restore asks a question like Archive does | `components/domain/portfolio/sub-product-page.tsx` | true | restores an archived record without asking |
| M16 | Archive is offered without the archive right | `components/domain/portfolio/sub-product-page.tsx` | true | offers Archive and Restore only to a caller whose rights allow them |
| M17 | Restore is offered without the restore right | `components/domain/portfolio/sub-product-page.tsx` | true | offers Archive and Restore only to a caller whose rights allow them |
| M18 | a shared record is archivable | `components/domain/portfolio/sub-product-page.tsx` | true | offers Archive and Restore only to a caller whose rights allow them |
| M19 | a save in the form no longer reads the record again | `components/domain/portfolio/sub-product-page.tsx` | true | reads the record again after a save in the form, and the new name reaches the heading and the breadcrumb |
| M20 | a real zero of signals is drawn as a dash | `components/domain/portfolio/portfolio-list-table.tsx` | true | draws a real zero of signals as 0 and a missing count as a dash |
| M21 | a missing count of signals is drawn as 0 | `components/domain/portfolio/portfolio-list-table.tsx` | true | draws a real zero of signals as 0 and a missing count as a dash |
| M22 | no ICP applies: the cell says nothing | `components/domain/portfolio/portfolio-list-chips.tsx` | true | draws a real zero of signals as 0 and a missing count as a dash |
| M23 | an archived Sub-product row is not marked | `components/domain/portfolio/portfolio-list-table.tsx` | true | marks an archived Sub-product, an archived Product and a shared Product |
| M24 | an archived Product header is not marked | `components/domain/portfolio/portfolio-list-table.tsx` | true | marks an archived Sub-product, an archived Product and a shared Product |
| M25 | a shared Product header is not marked | `components/domain/portfolio/portfolio-list-table.tsx` | true | marks an archived Sub-product, an archived Product and a shared Product |
| M26 | an archived Product header offers Add sub-product | `components/domain/portfolio/portfolio-list-table.tsx` | true | marks an archived Sub-product, an archived Product and a shared Product |
| M27 | a slower answer to an older request replaces the newest (the latest-only guard after the read removed) | `components/domain/portfolio/portfolio-list.tsx` | true | shows the answer to the newest search, not a slower answer to an older one |
| M28 | text that is not a number is turned into null | `lib/portfolio/form-model.ts` | true | sends text that is not a number as the text, so the server can say so |
| M29 | an ID may have 51 characters | `lib/portfolio/form-model.ts` | true | accepts an ID of 50 characters and refuses one of 51 |
| M30 | an ID may have spaces | `lib/portfolio/form-model.ts` | true | accepts an ID of 50 characters and refuses one of 51 |
| M31 | the Signals address is not encoded | `lib/portfolio/page-model.ts` | true | opens Company Opportunities for a Product, and for one of its Sub-products |
| M32 | a record with no Product key gets an address | `lib/portfolio/page-model.ts` | true | has no Signals address for a record with no Product key |
| M33 | a Sub-product link loses its ID | `lib/portfolio/page-model.ts` | true | opens Company Opportunities for a Product, and for one of its Sub-products |
| M34 | concept is no longer quiet | `lib/product-master/tones.ts` | true | draws every readiness state in its colour |
| M35 | prototype is drawn as good | `lib/product-master/tones.ts` | true | draws every readiness state in its colour |
| M36 | the built-in ideal-customer state loses its word | `lib/portfolio/page-model.ts` | true | says every ideal-customer state in words, and Not applicable when none applies |
| M37 | the list says Drafted for a draft ideal customer | `lib/portfolio-list/model.ts` | true | gives every ideal-customer state of the list its own word and tone |
| M38 | a built-in ideal customer is drawn plain, not informational | `lib/portfolio-list/model.ts` | true | gives every ideal-customer state of the list its own word and tone |
| M39 | a tab change does not reach the tab state (and so not the address) | `components/domain/portfolio/sub-product-page.tsx` | true | keeps the page and says what failed when the usage cannot be read; says it is in no price book yet, and what a quote uses instead; draws a real zero in the usage counts as 0; says Signals has no value for a record that was not counted, and draws a real zero as 0; writes the tab to the address when the person changes it, and leaves the plain address on the Overview; opens the Usage of a catalogue-only record, and says on its Signals & ICP tab that it has no About yet, asking |
| M40 | the Signals & ICP tab asks the Portfolio for an ideal customer even when the record has no About | `components/domain/portfolio/sub-product-page.tsx` | true | opens the Usage of a catalogue-only record, and says on its Signals & ICP tab that it has no About yet, asking |
| M41 | the no-About sentence changes | `components/domain/portfolio/sub-product-tabs.tsx` | true | opens the Usage of a catalogue-only record, and says on its Signals & ICP tab that it has no About yet, asking |
| M42 | a record with a Sell section and no About gets no Usage tab | `lib/portfolio/page-model.ts` | true | keeps the page and says what failed when the usage cannot be read; says it is in no price book yet, and what a quote uses instead; draws a real zero in the usage counts as 0; writes the tab to the address when the person changes it, and leaves the plain address on the Overview; opens the Usage of a catalogue-only record, and says on its Signals & ICP tab that it has no About yet, asking |

First-pass line of M09 as the runner printed it: `M09 SURVIVED | a count the server did not send is drawn as a count | lib/portfolio/page-model.ts | restored identical: true | by: (none)`.

## What the guard mutations show
- G01 and G02: an old Products or Services address written back into a live component fails "names neither old address anywhere outside the allowances".
- G03 and G04: an old component imported from a live route or a content map fails "finds none in the pages, the content maps, or the new list, menu, review and redirect".
- G05 and G06: an old component, or the old product-master model, brought back as a file fails "has none of the old components, models or test kits on disk".
- G07: a stale allowance prints "the allowance for lib/utils.ts is no longer needed ...". The real run of the shrunken guard printed no such line, so every allowance left has a live reason.
- C01: a retired product-line component brought back fails "finds no component of the retired Products and Services screens, so none is left to scan or to import". C02: a status colour as the text of a chip fails the live scan in `lib/portfolio/contrast.test.ts` ("uses no status colour as TEXT ..."), the rule the deleted scan applied.
