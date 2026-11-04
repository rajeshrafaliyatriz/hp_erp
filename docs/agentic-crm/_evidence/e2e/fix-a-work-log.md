# FIX-A work log (front end, g2gv0 branch milan1) - append only

Started 2026-11-04. Brief: docs/agentic-crm/briefs/FIX-e2e-findings.md Track A (A1 to A6). Rules: no commit, no push, no npx, one test process at a time, no server.
Coordinator change: the opportunity board scroll-box line in A6 (opportunity-kanban-view.tsx ~206) now belongs to S11b. SKIPPED by me.
g2gv0 working tree at start (S11b's, not mine): create-opportunity-modal.tsx, opportunity-detail-page.tsx, opportunity-kanban-view.tsx, opportunity-list-view.tsx, services/crm/index.ts, types/crm.ts modified; opportunity-form-options.ts, opportunity-parts.tsx, lib/opportunities/ new.

Tags: verified / read in source / inferred / unverified.

## 2026-11-04 resume after the session limit (coordinator message, window ends about 13:20)

State list (git status in g2gv0 re-read; my files only):
- DONE A1 (verified): components/domain/crm/sales-order-detail-page.tsx now owns the CreateInvoiceModal (prefillFromSalesOrderId = the order id, opens the new invoice, or shows the message when the menu has no Invoices row; the dead optional onCreateInvoice prop is removed, the route file is unchanged); components/domain/crm/create-invoice-modal.tsx onSaved gets the saved invoice as an optional 2nd argument. Test lib/sales-orders/sales-order-invoice.test.tsx 6/6 green. Mutations: 6/6 killed, files restored (cmp) - _evidence/e2e/fix-a-A1-mutations.txt. Backend route read in source: POST /crm/invoices/from-sales-order/{id} accepts a sales order id (CrmInvoiceController::fromSalesOrder, routes/api.php:4181, right = invoices add). No backend change needed for the button.
- BACKEND OBSERVATION for the caller (read in source, not edited): app/Domain/Crm/Sales/SalesDocuments.php invoiceFromSalesOrder() copies lines, tax and discount but NOT shipping_handling_amount or adjustment; recomputeTotal adds both. So an order with shipping or an adjustment gives an invoice whose total differs from the order's. Orders with neither (the E2E case is inferred to be that) match.
- NOT MINE, in the tree (other tracks): campaign-options.ts, outreach-view.tsx, outreach-checks.tsx, outreach-claims.tsx, lib/outreach, services/outreach (S10b); create-opportunity-modal, opportunity-* , services/crm/index.ts, types/crm.ts, lib/opportunities (S11b). I do not touch them.
- Half done / broken: none of mine. Next: A2.

### A2 DONE (verified) + A6 lead-page success banners DONE (verified)
- New components/domain/crm/lead-detail-screen.tsx (LeadDetailScreen: loads the lead and picklists; "Opening lead" only on the FIRST load of an id; a reload keeps LeadDetailPage mounted so its notice survives; a failed reload keeps the page). app/module/crm/marketing/leads/[id]/page.tsx now only holds the shell and breadcrumb and draws <LeadDetailScreen id onBack onLead={setLead} />.
- components/domain/crm/lead-detail-page.tsx: the notice box and the "was converted to" box now use NOTICE_TONE.success (data-lead-notice / data-lead-converted markers, role=status on the notice).
- Tests: lib/leads/lead-detail-screen.test.tsx (7), lib/leads/notice-contrast.test.ts (5): 12/12 green. Mutations 8 killed + 1 re-run (original bug) killed, all restored by cmp: _evidence/e2e/fix-a-A2-A6lead-mutations.txt. Finding while proving it: an instant reload answer is batched into one paint and HIDES the bug in jsdom; test 1 now answers after 25 ms so the intermediate paint happens (the mutation is killed by 3 tests).
- Open, not in the brief (read in source, unverified in a browser): lead-detail-page.tsx Details tab consent field uses 'bg-success/15 text-success' (the opted_in value); likely the same low contrast as the banner. Not changed.
### A3 DONE (verified)
- components/domain/crm/create-lead-modal.tsx: a NEW lead's owner starts as the signed-in person (context.userId); placeholder is "Choose an employee..."; a hint under the field (data-assign-hint, new leads only) says "Starts as you ... clear it only if your organisation has automatic assignment turned on, otherwise saving is refused". Editing keeps the lead's own owner. Server rule read in source: CrmLeadController::store() 422 "Choose who this lead is assigned to (no auto-assignment rotation is configured)" when LeadAssigner::next() gives null; blank stays valid on hosts with rotation, so the field is not made required.
- Test lib/leads/create-lead-modal.test.tsx 3/3; mutations 6/6 killed (one survived first: the placeholder is hidden while the field holds a value; test now also scans the source; re-run killed), all restored by cmp: _evidence/e2e/fix-a-A3-mutations.txt.

### A5 STOPPED - needs a backend change (read in source, verified by reading, not run)
- app/Http/Controllers/Api/Crm/CrmLeadController.php: neither store() (validator ~lines 198-215) nor update() (validator ~line 268) mentions product_master_id / productMasterId, and payload() (~lines 676-735) is a fixed map of request key -> column that has NO product entry, so a product sent by the form would be dropped silently. Only LeadFactory::withLinks (app/Domain/Crm/Leads/LeadFactory.php ~lines 54-67, 'product_master_id' => LeadLinks::productVisible) knows the column, and only for callers that put it in $data (the signal-to-lead path).
- What the backend needs (for the caller or Track B): in CrmLeadController add an optional `productMasterId` (nullable|integer) to the store and update validators; map it to product_master_id in payload() ONLY when ColumnExists::all('crm_leads') has the column (the same guard index() uses at ~line 107) and only when LeadLinks::productVisible says the product is this organisation's or shared (a refused one is dropped, as LeadFactory does); update() must treat an absent key as "unchanged" and an explicit null as "clear". resource() already returns `product` ({id,key,name}) at ~line 843.
- Front end already in place: the lead page header shows "Product: <name>" when the lead has one ([data-lead-product], proven by lib/leads/lead-detail.test.tsx). Still to build once the backend accepts it: a Product select in create-lead-modal.tsx fed by GET /api/product-master/options (as the Leads filter does), and productMasterId on LeadPayload in types/crm.ts (shared file: re-read before editing). I did NOT build the select now, because against the current server it would look saved and silently save nothing.

### A4 DONE (verified)
- components/domain/crm/campaign-list-view.tsx: a 403 on the list (ApiError.status) now draws a "You do not have access to this screen." panel (data-campaigns-denied, role=status) instead of the table, search, Import and Add Campaign; any OTHER failed load now says "The campaigns could not be loaded." in the table instead of "No campaigns yet".
- Test lib/campaigns/campaign-list-denied.test.tsx (4) + the existing lib/campaigns/campaign-list.test.tsx (11) = 15/15 green; mutations 5/5 killed, restored by cmp: _evidence/e2e/fix-a-A4-mutations.txt.
- Not done (outside the brief, noted): the Campaigns menu row 453 is hidden for everyone, so this denied screen is reachable only by URL.

### A6 DONE except the kanban line (verified); the kanban scroll box belongs to S11b (coordinator message) and was NOT touched
- components/domain/gtm/campaigns-detail.tsx: segment Select in "Add targets" has aria-label "Segment to add targets from"; Remove button and the Archive / Mark completed buttons now use one tone DESTRUCTIVE_BUTTON = 'border-destructive text-foreground' (words in foreground on the page background, only the border coloured); each Remove button is named "Remove <target> from this campaign".
- components/domain/gtm/reports-view.tsx: the table's scroll box is role=region, tabIndex 0, aria-label "<report title> results", with a focus ring.
- components/domain/crm/lead-detail-page.tsx: both success boxes use NOTICE_TONE.success (done with A2).
- A4's denied Campaigns state is A4.
- Tests: lib/campaigns/gtm-campaign-a11y.test.tsx (7), lib/gtm-reports/reports-view-a11y.test.tsx (3); with the existing gtm-campaign tests 18/18; mutations 8/8 killed, restored by cmp: _evidence/e2e/fix-a-A6-mutations.txt. axe (in jsdom) does catch the unnamed Select (button-name), so that mutation is killed by the axe test as well as by the attribute test.

### Final checks (verified, 2026-11-04 about 11:55)
- Whole folders I touched, one process: lib/leads, lib/campaigns, lib/sales-orders, lib/gtm-reports = 309 tests, 309 pass, 0 fail (_evidence/e2e/fix-a-touched-folders-run.txt).
- tsc --noEmit -p . : 7 errors, none in my files (.next/dev/types/validator.ts, crm-ai-assistant.tsx x4, opportunity-ai-coach-tab.tsx x2); the brief said 14 known, today it is 7.
- eslint --max-warnings=0 on every file I added or changed: clean EXCEPT 2 errors in lead-detail-page.tsx (react-hooks/set-state-in-effect at the tab effect, react/no-unescaped-entities in the Signals paragraph) that are IDENTICAL at HEAD (checked with git show HEAD:... piped to eslint --stdin); not mine, not fixed.
- git diff --stat components/ui components/shell: empty. No commit, no push, no server, no npx.
- Stale comment in lib/campaigns/gtm-campaign.test.tsx (last test: "a segment Select, a checkbox were already named by nothing"): no longer true for the Select after A6; the test still passes (it compares with and without the new lines). Another track's file; left alone.

- Process note: I used one shell heredoc to append to this log earlier (no backslashes in it, content intact); from here only Edit/Write.
