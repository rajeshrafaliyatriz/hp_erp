# Front-end mutation proofs of the folded Organisation 360 safeguards (track X, 3 Nov 2026)

Method (tag: verified, I ran it): a runner outside the repo (`mutate-js.cjs`) first runs every test file and requires it GREEN (baseline: model 47 tests, Account 360 tab 37, scope switch 24, contrast 23, shared-component fixes 10, tab list 4, service 13; these counts grew afterwards, see the end). For each mutation it replaces ONE exact snippet (the original must occur exactly once), runs the named test files with `node --import tsx --test`, counts a KILL only when the Node test runner itself reports failed tests, and restores the file from the bytes read before, comparing SHA-256 (all restored identical). Raw: `D:\dev\agentic-crm-research\scratch\x-js-mutations.json` and `.log`.

First pass result: **40 mutations, 39 killed, 1 runner error** (F08). The three weak spots it exposed were fixed and re-run by hand (below), so the final state is **40 of 40 killed, plus 5 more for the agent wiring (G01 to G05), all restored byte-identical**.

## What the sweep found about my own tests (and fixed)
F08, F16 and F20 did not fail cleanly: F16 and F20 ended the test process early (the file counted as one failure after 11 and 33 tests) and F08 never ended. Reproduced by hand: when `assert.equal(<DOM element>, null)` fails, Node prints the element and its whole jsdom object graph and the process dies with **"JavaScript heap out of memory" after 29 s**; and a failing `assert.ok(<long chained expression>)` with no message makes Node re-parse the call site in a tsx-compiled `.tsx` file, which **hangs**. A test that dies or hangs when it fails is worse than a weak test (a broken product would stall the run instead of naming the failure), so every assertion that could hand a DOM node to the printer now compares with `=== null` and every `assert.ok` in the three component test files (101 of them) now carries an explicit message. After the fix: F16 fails in 229 ms naming "offers the button only with the add right"; F20 in 209 ms naming "offers the button only with the edit right"; F08 in 215 ms naming "D2: marks a pair in two different places on its card, and the confirm question warns before anything is changed" (tab) and in 385 ms in the scope tests.

## The mutations
| # | What was broken on purpose | File | Result | First test that failed |
|---|---|---|---|---|
| F01 | D6: a lone record is told "reason not recorded" again | `lib/org-accounts/display.ts` | KILLED | says "No GTM account found" and "No Signals company found" ... (+4) |
| F02 | D2: every pair says "Different places" | `display.ts` | KILLED | says "Different places" only for a pair whose places conflict (+3) |
| F03 | the switch counts the lifecycle "discovered" instead of the organisations with no CRM organisation | `scope-model.ts` | KILLED | takes the counts from the org-accounts summary (+2) |
| F04 | the Discovered scope no longer asks for has_crm=0 | `scope-model.ts` | KILLED | always asks for the organisations that have NO CRM organisation (+3) |
| F05 | a 403 is no longer its own state | `scope-model.ts` | KILLED | tells the states apart |
| F06 | regression: Signal Review is looked up only under a spelling the menu never has | `links.ts` | KILLED | finds Signal Review under the access_link its menu row really has (+1) |
| F07 | the confirm question looks destructive | `suggestions.ts` | KILLED | asks a question that says what will happen, and neither answer looks destructive |
| F08 | D2: the confirm question no longer warns about different places | `suggestions.ts` | RUNNER-ERROR in the sweep (hang); after the fix KILLED | D2 in the tab tests and in the scope tests |
| F09 | D3: a pair of one source gets a Confirm button again | `org-account-duplicates.tsx` | KILLED | D3: a pair that can never be confirmed offers only "Not the same" |
| F10 | D2: the place-conflict marker is never drawn | `org-account-duplicates.tsx` | KILLED | D2: a pair in two different places says so on its card |
| F11 | D3: after a decision the pairs are not read again | `use-org-scopes.ts` | KILLED | D3: after a confirm the pairs are read again |
| F12 | after a decision the counts are not read again | `use-org-scopes.ts` | KILLED | the same test |
| F13 | the pairs are read on every page open | `org-scopes.tsx` | KILLED | asks for the counts once ... until a scope is opened |
| F14 | the restore answer restores nothing | `org-account-discovered.tsx` | KILLED | a deleted organisation with the same name: asks to restore it |
| F15 | creating no longer asks first | `org-account-discovered.tsx` | KILLED | asks first, and Cancel creates nothing |
| F16 | Create CRM organisation is offered without the add right | `org-account-discovered.tsx` | KILLED (after the fix: named) | offers the button only with the add right |
| F17 | the tab ensures the wrong organization's identity | `account-360.tsx` | KILLED | ensures the identity of THIS organization on opening |
| F18 | after a confirm the 360 is not read again | `account-360.tsx` | KILLED | confirming asks first, then links the two, then reads ... again |
| F19 | a 403 on the old sections is shown as an error to retry | `account-360.tsx` | KILLED | says plainly that a role without the Market Signals right cannot read them |
| F20 | the Edit button is offered without the edit right | `account-360.tsx` | KILLED (after the fix: named) | offers the button only with the edit right |
| F21 | a stored address that is not a web address becomes a link | `org-account-360-parts.tsx` | KILLED | never makes a link of a stored address that is not a web address |
| F22 | the evidence link loses rel="noopener noreferrer" | `org-account-360-parts.tsx` | KILLED | links the evidence page ... safely |
| F23 | closed CRM opportunities are listed as open | `org-account-360-parts.tsx` | KILLED | lists the open opportunities and the GTM deals, not the closed CRM ones |
| F24 | a possible match always shows the right-hand record as the candidate | `org-account-360-parts.tsx` | KILLED | picks the other record whichever side of the pair is this organization's own |
| F25 | the edit form's field error is not announced (no role="alert") | `org-account-edit-form.tsx` | KILLED | announces a field error as an alert, tied to its field |
| F26 | the edit form's control is not marked invalid | `org-account-edit-form.tsx` | KILLED | the same test |
| F27 | a 503 is read as an error to retry | `failure.ts` | KILLED | a server that is not set up says so, offers no retry |
| F28 | D4: the "target" chip text goes back to the status colour | `tones.ts` | KILLED | chip info reads at 4.5:1 or better in the dark theme |
| F29 | D4: the success notice text goes back to the status colour | `tones.ts` | KILLED | notice success reads at 4.5:1 or better in the light theme |
| F30 | D1: Cancel is no longer the dialog's cancel control | `components/ui/confirm-dialog.tsx` | KILLED | puts the focus on Cancel, inside the dialog |
| F31 | D1: a non-destructive confirm is red again | `confirm-dialog.tsx` | KILLED | keeps every existing caller's look |
| F32 | D5: the breadcrumb items are not in a list | `components/ui/breadcrumb.tsx` | KILLED | has the list items in an `<ol>` inside the nav |
| F33 | D7: the back button may shrink again | `components/shared/scaffolds/detail-page.tsx` | KILLED | does not shrink and has a 24 px floor |
| F34 | D8: a tab's name runs its label and count together | `components/shared/scaffolds/page-tabs.tsx` | KILLED | has three tabs, with the counts ... (the scope switch) |
| F35 | the GTM & Signals tab is back in place of Account 360 | `detail-tabs.ts` | KILLED | keeps the tabs it had, in order |
| F36 | an unknown ?tab= opens the wrong tab | `detail-tabs.ts` | KILLED | opens the tab a link asks for |
| F37 | the open deals include the closed CRM opportunities | `old-sections.ts` | KILLED | keeps the open opportunities and every GTM deal |
| F38 | the service asks the wrong URL for the 360 of a CRM organisation | `org-accounts-service.ts` | KILLED | asks for the 360 of a CRM organisation with a POST to /for-crm/{id} |
| F39 | the service never narrows the suggestions to one organisation | `org-accounts-service.ts` | KILLED | narrows the possible duplicates to one organisation only when asked to |
| F40 | D1: `useConfirm` drops the button's look | `use-confirm.tsx` | KILLED | a question that says tone "default" is not red |
| G01 | the page is no longer told when the agent changes records | `account-360.tsx` | KILLED | when the agent changes notes or tasks ... |
| G02 | accepting a link that merges organisations no longer re-reads this CRM organization | `account-360.tsx` | KILLED | when accepting a link merges this organization into another ... |
| G03 | the agent is handed the wrong identity | `account-360.tsx` | KILLED | hands the identity of THIS organization to the agent (+2) |
| G04 | the real wrapper forgets the merge callback | `account-360.tsx` | KILLED | is wired for real ... |
| G05 | the real tab does not use the real panel | `account-360.tsx` | KILLED | is wired for real ... |

Not mutation-checked, said plainly: the composition in `organization-list-view.tsx` (a 5-line addition around the unchanged CRM list; no unit test renders the whole list view, which needs the app's providers) and `organization-detail-page.tsx` (tab list now comes from `detail-tabs.ts`, tested; the page itself is covered by the real-browser run, not by a unit test).
