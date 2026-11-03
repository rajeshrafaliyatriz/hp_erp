# Agentic CRM — Feature ledger

One row per feature. A feature is done only with evidence and the owner's sign-off. Update this table whenever a slice file's status changes.

| Slice | Feature | Status | Files touched (representative) | Tests | Evidence path | Owner sign-off | Old screen: keep / hide / retire |
|---|---|---|---|---|---|---|---|
| S0 | Working system, baseline, safety | done 2026-11-02 | `Docs/agentic-crm/*` | n/a | `_evidence/` | | n/a |
| S1 | Product Master | **built and browser-checked on dev, 13 of 13 tasks (2026-11-02)**; not on live, not pushed | hp_erp: `database/migrations/2026_12_07_*`, `app/Domain/ProductMaster/*`, `app/Http/Controllers/Api/ProductMaster/ProductMasterController.php`, `routes/product_master.php`, `app/Console/Commands/ProductMaster*Command.php`. g2gv0: `components/shared/scaffolds/*`, `components/domain/crm/product-master-*`, `services/product-master/*`, `app/module/crm/product-master/*`, `hooks/content-map-crm.ts` | PHP: `ProductMasterTest` (14), `ProductMasterApiTest` (11), `ProductMasterWriteApiTest` (36). Front end: 114 under `lib/scaffolds/` and `lib/product-master/`. Browser walk-through: 73 checks | `_evidence/s1-product-master-dev-2026-11-02.md`, `s1-task5-menu-and-read-api-2026-11-02.md`, `s1-task6-write-api-2026-11-02.md`, `s1-tasks8-13-frontend-2026-11-02.md` | waiting for the owner's review of the screen | old Product Portfolio and CRM Products screens: keep (default), decided by the owner on: ______ |
| S2 | Signals front door (Signal Review, under Signals) | **built and verified on dev, 14 of 15 tasks (3 Nov 2026)**; not on live, not pushed; task 14 (live rollout) waits for the owner's go | hp_erp: `database/migrations/2026_12_08_09*`, `app/Domain/Signals/Review/*`, `Opportunities/{DateStatus,FeedFilters,SignalStamps}.php`, `Run/{RunContext,RunCost}.php`, `Backfill/*`, `Console/Commands/Signals*Command.php`, `OpportunityController` (decide, reviews, act, promote, index filters, presentRun), `EventCatalogue`, `routes/api.php`, `config/signals.php`. g2gv0: `components/domain/crm/signal-*.tsx`, `services/signal-review/*`, `lib/signal-review/*`, `app/module/crm/signal-review/`, `app/signal-review/`, `hooks/content-map-crm.ts` | PHP: 567 across the S2 and neighbouring Signals files (new: SignalReviewApi 50, SignalFeedFilters 14 + 5, RunCost 25 + 4, SignalStamps 8, SignalsBackfill 26, SignalReviewMenu 13, SignalReviewSchema 8, DateStatus 14). Front end: 190. Real browser: 71 + 7 | `_evidence/s2-design-gate-checks-2026-11-03.md`, `s2-tasks1-3-merge-and-dev-schema-2026-11-03.md`, `s2-tasks4-9-dev-backfills-and-menu-2026-11-03.md`, `s2-tracks-review-2026-11-03.md`, `s2-browser-walkthrough-2026-11-03.md` | waiting for the owner's review of the screen and the go for live | old Company Opportunities / Market Signals screen: keep (default), decided by the owner on: ______ |
| S3 | Organisation 360 (Accounts › Organisations) | not started | | | | | |
| S4 | Contact facts ledger and consent | not started | | | | | |
| S5 | Contact and organisation discovery | not started | | | | | |
| S6 | Leads (Pipeline › Leads) | not started | | | | | |
| S7 | Agent gateway and autonomy settings | not started | | | | | |
| S8 | Ask (chat) | not started | | | | | |
| S9 | Campaigns to leads | not started | | | | | |
| S10 | Playbooks and outreach | not started | | | | | |
| S11 | Pipeline and forecast capture | not started | | | | | |
| S12 | Agent builder, triggers, run history | not started | | | | | |
| S13 | Paperwork re-skin | not started | | | | | |
| S14 | Retire old screens | not started | | | | | |
