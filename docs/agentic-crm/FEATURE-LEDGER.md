# Agentic CRM — Feature ledger

One row per feature. A feature is done only with evidence and the owner's sign-off. Update this table whenever a slice file's status changes.

| Slice | Feature | Status | Files touched (representative) | Tests | Evidence path | Owner sign-off | Old screen: keep / hide / retire |
|---|---|---|---|---|---|---|---|
| S0 | Working system, baseline, safety | done 2026-11-02 | `Docs/agentic-crm/*` | n/a | `_evidence/` | | n/a |
| S1 | Product Master | **built and browser-checked on dev, 13 of 13 tasks (2026-11-02)**; not on live, not pushed | hp_erp: `database/migrations/2026_12_07_*`, `app/Domain/ProductMaster/*`, `app/Http/Controllers/Api/ProductMaster/ProductMasterController.php`, `routes/product_master.php`, `app/Console/Commands/ProductMaster*Command.php`. g2gv0: `components/shared/scaffolds/*`, `components/domain/crm/product-master-*`, `services/product-master/*`, `app/module/crm/product-master/*`, `hooks/content-map-crm.ts` | PHP: `ProductMasterTest` (14), `ProductMasterApiTest` (11), `ProductMasterWriteApiTest` (36). Front end: 114 under `lib/scaffolds/` and `lib/product-master/`. Browser walk-through: 73 checks | `_evidence/s1-product-master-dev-2026-11-02.md`, `s1-task5-menu-and-read-api-2026-11-02.md`, `s1-task6-write-api-2026-11-02.md`, `s1-tasks8-13-frontend-2026-11-02.md` | waiting for the owner's review of the screen | old Product Portfolio and CRM Products screens: keep (default), decided by the owner on: ______ |
| S2 | Signals front door (Today › Signals) | not started | | | | | |
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
