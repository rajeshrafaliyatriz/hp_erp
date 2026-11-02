# Agentic CRM — Feature ledger

One row per feature. A feature is done only with evidence and the owner's sign-off. Update this table whenever a slice file's status changes.

| Slice | Feature | Status | Files touched (representative) | Tests | Evidence path | Owner sign-off | Old screen: keep / hide / retire |
|---|---|---|---|---|---|---|---|
| S0 | Working system, baseline, safety | done 2026-11-02 | `Docs/agentic-crm/*` | n/a | `_evidence/` | | n/a |
| S1 | Product Master | in progress: backend done on dev (tasks 2, 3, 4, 5, 6, 7); screens (8 to 13) not started | `database/migrations/2026_12_07_*`, `app/Domain/ProductMaster/*`, `app/Http/Controllers/Api/ProductMaster/ProductMasterController.php`, `routes/product_master.php`, `app/Console/Commands/ProductMaster*Command.php` | `tests/Feature/ProductMasterTest.php` (14), `ProductMasterApiTest.php` (11), `ProductMasterWriteApiTest.php` (29) | `_evidence/s1-product-master-dev-2026-11-02.md`, `_evidence/s1-task5-menu-and-read-api-2026-11-02.md`, `_evidence/s1-task6-write-api-2026-11-02.md` | | |
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
