# S6b (Leads front end): mutation proof, 4 Nov 2026

Repo: `C:\Users\MILAN\Downloads\g2gv0`, branch `milan1`, nothing committed. Tests: `node --import tsx --test "lib/leads/*.test.ts" "lib/leads/*.test.tsx"`.

## Method (verified)

A runner (kept outside the repo, in the session scratchpad: `mutate.cjs` with three spec files) does this for each mutation:
1. First, a BASELINE run of the whole lead test set, which must exit 0 with 0 failures and more than 100 tests (a command error would otherwise look like a kill). Baseline of this run: **129 tests, 129 pass, 0 fail**.
2. Copies the file to a backup, checks that the text to break occurs EXACTLY ONCE (otherwise "NOT APPLIED", never counted), writes the broken file.
3. Runs the lead tests and records the NAMED failing tests (a run that fails with no named test is not counted as a kill).
4. Restores the file from the backup and compares it with the backup byte for byte (`Buffer.equals`); a mismatch stops the run. **0 mismatches.**

Final run over all three batches: **97 mutations, 97 killed, 0 survived, 0 not applied.**

## What the sweeps found (the proof that the tests can fail)

- First sweep (71 mutations): 68 killed, **2 survived**, 1 not applied.
  - M09 (the consent filter accepts any text): survived because the address reader already whitelists consent, so no test handed `leadFilters` a bad value directly. Added a test that does. Killed on re-run.
  - M15 (a `has_signal` filter does not count as "active", so "Clear filters" would not appear for it): no test used the Origin box alone. Added a screen test and a model test. Killed on re-run.
  - M27 was "not applied": my find text used CRLF but the new file is LF (the runner refused to count it). Fixed the spec; killed on re-run.
- Second batch (16 mutations of rules the first did not name): 14 killed, **2 survived**: X08 (a gate that allows should read "Opted in" when the record also says opted in: untested) and X09 (a signal with no fit must have `fitText` null, not an empty string: the UI hid both). Two tests added; both killed on re-run.
- After reading hp_erp's in-progress `LeadSignalEvidence` (S6a), the Evidence tab gained the quote kind, the publication-day fallback and the captions; 10 more mutations (N01 to N10) were added, and the whole 97 were re-run from scratch for the final result.

## The 97 (id, the rule broken, how many named tests failed, the first one)

| id | rule broken | failed | first failing test |
|---|---|---|---|
| M01 | snake_case organisation_account is read | 7 | a list from the new server has its contract fields read into the names the screens use |
| M02 | camelCase sourceSignalId is read | 2 | reads the presenter's camelCase names just the same |
| M03 | snake_case source_signal_id is read | 9 | marks a lead made from a signal, in words, beside its company |
| M04 | a key the server did not send stays out (absent is not null) | 5 | a list from an older server comes back as it was sent, with no field invented |
| M05 | an id must be a whole number from 1 | 7 | drops what cannot be trusted ... |
| M06 | a blank product name becomes Product #id | 2 | drops what cannot be trusted ... |
| M07 | a consent channel with no yes/no is dropped, not assumed | 2 | drops what cannot be trusted ... |
| M08 | withLeadExtras adds the read fields | 7 | marks a lead made from a signal, in words, beside its company |
| M09 | consent filter is allowed or blocked only | 2 | leadFilters does not lean on the address reader ... |
| M10 | product_id must be a real id | 3 | a mistyped address opens the unfiltered list, never an error and never a wrong filter |
| M11 | an unfiltered product sends nothing | 3 | the default view has a clean address and means no filter at all |
| M12 | has_signal is the address name | 11 | the Consent and Origin boxes write the contract's names to the address and the request |
| M13 | a product the address names is kept in the box | 4 | keeps a product the address names but the list does not hold ... |
| M14 | the Product box can always be cleared | 2 | is drawn only when there is a product to choose or one is already chosen |
| M15 | a signal filter counts as active | 4 | each box on its own turns Clear filters on ... |
| M16 | a view with no filters leaves filters alone | 2 | a view saved before the filters existed leaves the person's filters alone |
| M17 | a shown column keeps its place | 2 | a column that has shown keeps its place on the next page |
| M18 | a product filter shows its column | 4 | choosing a product writes it to the address, asks the server for it, and the column stays ... |
| M19 | the table keeps eight columns plus the tick box | 5 | the empty and loading rows span exactly the columns that are drawn |
| M20 | the Product column is counted in the span | 4 | the empty and loading rows span exactly the columns that are drawn |
| M21 | a suppressedAt date blocks as suppressed | 2 | a suppressed lead is BLOCKED as suppressed, and suppressed is checked before opted out |
| M22 | the email opt-out flag blocks | 5 | says in words when a lead is blocked, and what it recorded ... |
| M23 | the gate's answer wins | 7 | says each channel in words with the reason in plain words |
| M24 | a blocking gate with no sentence is explained from the record, else honestly | 2 | a gate that blocks without a sentence is explained from the record ... |
| M25 | a blocked SMS channel always has a reason | 2 | SMS is read from the gate only ... |
| M26 | a lead with unknown consent says nothing | 4 | says in words when a lead is blocked ... and nothing for a lead nobody recorded anything for |
| M27 | suppressed wins the word over opted out | 6 | without the gate it reads the lead's own record ... |
| M28 | the older source column makes a lead "from a signal" | 7 | a lead that says it came from a signal the server did not send says so honestly |
| M29 | the new id makes a lead "from a signal" | 5 | marks a lead made from a signal, in words, beside its company |
| M30 | a source link is http(s) only | 4 | never makes a link of an address that is not http(s) ... |
| M31 | an id with no signal behind it is "unreadable" | 4 | a lead that says it came from a signal the server did not send says so honestly |
| M32 | an undated signal says Date unknown | 5 | says "Date unknown" for an undated signal ... |
| M33 | a quote of exactly the limit is not cut | 2 | a quote is shown only when the server sends one, and a long one is cut with an ellipsis |
| M34 | a link text drops a leading www. | 2 | hostOf drops a leading www. ... |
| M35 | a fact only patches the page when the column was written | 2 | writes nothing for a field kept in the ledger only ... |
| M36 | a job title lands in designation | 2 | writes the lead's own property for each field a lead has a column for |
| M37 | only a fact about a lead patches a lead | 2 | writes nothing for a field kept in the ledger only ... |
| S01 | product_id is the parameter name | 7 | choosing a product writes it to the address, asks the server for it ... |
| S02 | has_signal=1 is the parameter value | 8 | the Consent and Origin boxes write the contract's names to the address and the request |
| S03 | a product id of 0 means all (nothing is sent) | 2 | treats a product id of 0 and a false hasSignal as "all" |
| S04 | the list reads the new fields | 4 | marks a lead made from a signal, in words, beside its company |
| S05 | consent sends the chosen value | 2 | sends allowed as well as blocked for consent |
| S06 | one lead reads the new fields | 2 | one lead has its signal and its consent read, in either spelling |
| C01 | the Product column shows only when there is something to show | 6 | looks exactly as it always did: the same columns, no Product box, no chips, a clean address |
| C02 | changing a filter goes back to page 1 | 2 | changing a filter goes back to the first page |
| C03 | a filtered empty list does not say "No leads yet" | 3 | choosing a product writes it to the address ... |
| C04 | the filters are sent with the list request | 8 | choosing a product writes it to the address ... |
| C05 | Clear filters shows only while a filter is on | 3 | Clear filters shows only while a filter is on, and puts the address and the request back |
| C06 | a lead from a signal has its marker | 4 | shows the Consent and Origin boxes even without a product ... |
| C07 | the Product box is drawn only when there is a product to choose | 4 | looks exactly as it always did ... |
| C08 | the Origin box writes has_signal | 4 | the Consent and Origin boxes write the contract's names ... |
| C09 | applying a saved view applies its filters | 2 | applying a saved view that holds filters puts them in the address and the request |
| C10 | saving a view keeps the filters | 2 | saving a view keeps the filters that are on, beside the search and the sort |
| C11 | the list stays as before when the products cannot be read | 2 | when the products cannot be read ... the Product box is simply not drawn |
| C12 | the Evidence tab exists | 17 | keeps its header and its tabs, and adds only an Evidence tab beside Details |
| C13 | the signal button opens the Evidence tab | 2 | a lead made from a signal has a button to its evidence, and pressing it opens the Evidence tab |
| C14 | a fact that wrote the lead shows on the page | 2 | a fact accepted here that wrote the lead's own field shows in the Details tab at once |
| C15 | the Evidence tab is lazy (no facts request before it opens) | 4 | does not ask for the facts until the Evidence tab is opened |
| P01 | nothing to say draws nothing | 2 | keeps its header and its tabs ... |
| P02 | the chip tooltip carries the reason | 2 | says in words when a lead is blocked ... |
| P03 | a blocked chip has the blocked look | 2 | says in words when a lead is blocked ... |
| P04 | a reason is shown for a blocked channel | 3 | says each channel in words with the reason in plain words |
| P05 | no status colour as text in the new parts (the scan) | 2 | uses no status colour as TEXT ... |
| P06 | the product name is drawn | 2 | names the product and the organisation account it is for |
| T01 | a source link opens safely | 2 | shows the signal it came from: title, date, fit, and the source as a link that opens safely |
| T02 | no Signal Review link without a menu row | 2 | links to Signal Review for that signal when the person's menu has it ... |
| T03 | the facts section is about a lead | 2 | the Evidence tab says honestly that the lead was not made from a signal, and still shows the facts |
| T04 | the accepted fact is passed on to the page | 2 | a fact accepted here that wrote the lead's own field shows in the Details tab at once |
| T05 | a quote is drawn when the server sent one | 3 | shows a quote only when the server sends one |
| K01 | the blocked chip keeps words on a solid surface (contrast) | 5 | chip blocked reads at 4.5:1 or better in the dark theme |
| K02 | the signal chip keeps its contrast | 3 | chip signal reads at 4.5:1 or better in the dark theme |
| X01 | camelCase organisationAccount is read | 2 | reads the presenter's camelCase names just the same |
| X02 | the screen-reader sentence carries the reason | 4 | says in words when a lead is blocked ... |
| X03 | SMS says nothing without the gate | 7 | keeps its header and its tabs ... |
| X04 | no filter is the empty box value | 3 | a view saved with no filter on clears the person's filters when applied |
| X05 | the Product box is drawn when there is a product to choose | 6 | draws the Product box with every product the Product Master lists |
| X06 | unchanged columns are the same object | 2 | a column that has shown keeps its place on the next page |
| X07 | All products is the first choice | 2 | offers All products first and then every record by its own label |
| X08 | a gate that allows says Allowed or Opted in | 2 | a gate that allows says "Opted in" when the record also says so ... |
| X09 | a signal with no fit leaves the fit out | 2 | a signal with no fit recorded has NO fit (null), not an empty one |
| X10 | an untitled signal is named by its number | 2 | an undated signal says "Date unknown" ... and an untitled one is named by its number |
| X11 | the event date is formatted from a real day only | 6 | shows the signal it came from ... |
| X12 | the Consent box shows the chosen value | 2 | opens from an address that names the filters ... |
| X13 | the organisation account is drawn in the header | 2 | names the product and the organisation account it is for |
| X14 | the signal marker says "From a signal" | 2 | marks a lead made from a signal, in words, beside its company |
| X15 | an unreadable signal says so | 2 | a lead that says it came from a signal the server did not send says so honestly |
| X16 | a lead with no signal says so | 2 | the Evidence tab says honestly that the lead was not made from a signal ... |
| N01 | the quote kind is read | 2 | reads the quote, what kind of text it is, and the publication day ... |
| N02 | only the two kinds the server defines are kinds | 2 | reads the quote, what kind of text it is, and the publication day ... |
| N03 | the publication day is the fallback date | 4 | shows the day the source was published when no event date is stated, and says so |
| N04 | the event date wins over the publication day | 5 | shows the signal it came from ... |
| N05 | an observed event is called a description, not a quote | 4 | calls the source's own words a quotation and a description of what happened a description ... |
| N06 | a text of unknown kind is not claimed to be copied from the source | 4 | calls the source's own words a quotation ... |
| N07 | a kind with no text is dropped | 2 | what the text under a signal is called depends on what it is ... |
| N08 | an observed event is not drawn as a quotation | 2 | calls the source's own words a quotation ... |
| N09 | the publication day is read | 2 | reads the quote, what kind of text it is, and the publication day ... |
| N10 | a text of unknown kind is marked unsaid | 2 | calls the source's own words a quotation ... |

## Not proved by a mutation (honest limits)

- Contrast is computed from the real theme tokens (`app/globals.css`) for the class strings in `lib/leads/tones.ts`, light and dark, and scanned in the two new components; it is NOT measured on a rendered page (jsdom computes no colour). A real browser's axe run is the caller's check.
- The fake-DOM axe run (rules that look at markup, names and roles) passes on the list with every new part drawn and on the lead page with the Evidence tab open; colour-contrast is switched off there for the same reason.
