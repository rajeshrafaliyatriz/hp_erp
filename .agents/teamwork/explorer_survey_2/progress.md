# Progress Heartbeat - explorer_survey_2

Last visited: 2026-11-06T07:10:00Z
Status: Completed
Phase: Account, Contact, and Lead Resolution Survey

## Tasks
- [x] Initialized DISPATCH.md and BRIEFING.md
- [x] Inspect database schema and Eloquent models for:
  - `g2g_org_accounts` (`App\Domain\OrgAccounts\OrgAccount`, `SoftDeletes`, `SkipsGuardableColumnCheck`)
  - `crm_organizations` (Query Builder & Controller `CrmOrganizationController`)
  - `crm_contacts` (Query Builder & Controller `CrmContactController`, `GtmContact` for GTM)
  - `crm_leads` (Query Builder, `LeadFactory`, `LeadScorer`, `LeadNumber`)
- [x] Investigate relationship to Tenant 6 (`sub_institute_id = 6`, Admin User ID 28, `product_master_id = 1` ScholarClone)
- [x] Investigate exact-match deduplication and dual table mapping (`g2g_org_accounts` vs `crm_organizations`)
  - Evaluated `OrgNormalizer::name()` / `OrgNormalizer::domain()`, `OrgEnsure::forCrmOrganization()`
  - Cataloged loose pointers and `UNIQUE KEY` constraints
- [x] Investigate persona extraction/creation in `crm_contacts` (Author, Head of HR, CFO/Finance Head)
  - Analyzed 3 LinkedIn signals (VN Softverg Technology, Creuto, Anjuli Subedi / Gargi Media House)
  - Cataloged required columns and idempotency match rules (email / name within organization)
- [x] Investigate lead creation & scoring formula in `crm_leads`
  - Cataloged `LeadFactory::create()`, `LeadNumber::claim()`, `LeadScorer::score()`
  - Verified active rules for Tenant 6 (`industry=Education` +25, `rating=Hot` +15 => 40 points total)
- [x] Compile comprehensive `handoff.md` and message orchestrator
- [x] Verify automated test suites (`LeadPromotionLinksTest`: 17 passed, `OrgAccountsSchemaTest`: 7 passed, `LeadNumberTest`: 11 passed)
