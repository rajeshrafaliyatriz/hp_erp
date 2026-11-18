# S3 (Organisation 360) — design-gate checks, 3 Nov 2026

Everything here is **read-only** (dev connection `mysql`, MariaDB 10.11; live connection `live`, MariaDB 10.1.48) or read from source. Tags: **verified** (I ran the query and read the result), **read in source** (I read the line), **inferred**. Scripts were throw-away one-offs; the figures below are what they printed.

## The three places an "organisation" lives today (verified)

| table | what it is | dev (all / org 6) | live (all / org 6) |
|---|---|---|---|
| `crm_organizations` | the CRM account that quotes, orders, invoices, tickets and contacts hang from | 5 rows (3 soft-deleted) / 2 live | 2 / 1 |
| `gtm_accounts` | the GTM (outreach) account; has `crm_organization_id` (FK) and a loose `company_id` | 4 / 4 | 7 / 7 |
| `g2g_companies` | the Signals company a signal is about | 45 / 45 | 61 / 60 |

Related: `g2g_company_aliases` 41 dev / 35 live, `g2g_target_accounts` 389 dev / 25 live, `crm_contacts` 2 / 2, `crm_leads` 6 dev / 5 live (3 in org 6 on live).

## How they overlap, organisation 6 (verified)

| measure | dev | live |
|---|---|---|
| `gtm_accounts` already linked to a CRM organisation | 2 of 4 | **0 of 7** |
| `gtm_accounts` already linked to a Signals company (`company_id`) | 2 of 4 | 6 of 7 |
| rows with a usable domain: CRM / GTM / Signals | 0/2, 1/4, 16/45 | 0/1, 5/7, 19/60 |
| exact-domain matches: CRM~GTM / CRM~Signals / GTM~Signals | 0 / 0 / 0 | 0 / 0 / **4** |
| exact normalised-name matches: CRM~GTM / CRM~Signals / GTM~Signals | 2 / 1 / 3 | 0 / 0 / **6** |
| duplicates inside one table (same normalised name) | 0 / 0 / 0 | 0 / 0 / 0 |
| fuzzy (>= 85%) GTM~Signals name pairs that are not exact | 0 | 0 |
| Signals companies that already have a GTM account (via `company_id`) | 2 | 6 |

Reading: on live the real overlap is small and clean. A backfill by exact domain or exact normalised name links 6 of the 7 GTM accounts to their Signals company (4 by domain, 6 by name, 6 already linked by `company_id`), links nothing to the one CRM organisation, and finds no duplicates and no fuzzy near-matches to put in front of a person. (*Normalisation here: lower-case, strip protocol / `www` / path for domains; lower-case, strip punctuation and the words pvt, ltd, llp, inc, private, limited, school, the for names.*)

## Defects the plan assigned to S3, re-checked

- **D2 Account 360 queries columns that do not exist: CONFIRMED (read in source + schema, both hosts).** `CrmKnowledgeGraphController` line 66 selects `opportunity_name` and `close_date` from `crm_opportunities`; the real columns are `name` and `closing_date` on dev and live (`opportunity_name`: NO, `close_date`: NO). Line 87 selects `c.campaign_name`; the campaigns migration defines the column as `name` (read in source). So that endpoint fails with an SQL error whenever an organisation has an opportunity or a campaign target. The new Organisation 360 gets its own endpoint; the old one is left alone.
- **D9 email columns are too narrow: CONFIRMED.** `crm_organizations.email` and `secondary_email`, and `crm_contacts.email` / `secondary_email`, are `varchar(100)` on both hosts, while validation allows 191. Live is not strict, so a longer address is silently truncated.
- **D10 the unique name key counts deleted rows: CONFIRMED.** `crm_orgs_tenant_name_unique (sub_institute_id, name)` has no `deleted_at`. On dev, 3 soft-deleted organisations still hold their names ("Acme Test Corp", "ZZ UI Quote Test Org", "ZZ SMS Test Org"), so those names cannot be created again; live has none today.
- **D10 the merge repoints almost nothing: CONFIRMED (read in source).** `CrmOrganizationController::merge` repoints only `crm_contacts.organization_id`, `crm_leads.converted_organization_id`, `crm_organizations.parent_id` and the campaign targets. Tables that carry an organisation id (by column name, dev schema): `crm_assets`, `crm_contacts`, `crm_invoices`, `crm_leads` (`converted_organization_id`), `crm_opportunities`, `crm_organization_comments`, `crm_purchase_orders`, `crm_quotes`, `crm_sales_orders`, `crm_service_contracts`, `crm_tickets`, `gtm_accounts.crm_organization_id`, `task_management_projects.crm_organization_id`, plus polymorphic links (`crm_email_links.linked_type`, `document_library_links.linked_type`, `crm_consent_preferences.entity_type`, `crm_knowledge_sources.entity_type`, calendar events, and others). So merging two duplicates leaves their quotes, orders, invoices, tickets, assets, contracts, comments, projects and GTM account pointing at the soft-deleted duplicate. The `gtm_*.account_id` columns point at `gtm_accounts`, not at the CRM organisation.

## What the numbers say about the design (inferred; the owner's choices are listed in the slice file)

1. The data is small, so a **thin identity table with loose pointers** (the same pattern as the Product Master, no merge of the three tables) is cheap and safe; there is no heavy migration risk.
2. Exact-domain and exact-name backfill is enough to start; a fuzzy "possible duplicates" list would be empty on today's live data, so it is built but small.
3. The merge fix is the real engineering item: every referencing table must be repointed in one transaction, with a test that lists the referencing tables from `information_schema` and fails when one is not covered.
4. The CRM side is almost empty on live (1 organisation, 2 contacts), so the Organisation 360 must be useful from **Signals and GTM data first**, and must offer "create the CRM organisation from this signal company" as a first-class action.

## Not verified

The polymorphic type values actually stored (UPPERCASE vs lowercase) in each link table; whether any other host holds organisation data; the Signals `g2g_target_accounts` relationship to organisations (389 dev rows of target schools: whether they should appear as organisations is a question for the owner); what the Accounts menu looks like on live.
