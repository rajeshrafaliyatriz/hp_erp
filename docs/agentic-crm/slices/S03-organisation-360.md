# S3 — Organisation 360

**Status:** design decided by the assistant on 3 Nov 2026 under the owner's standing instruction ("take decisions yourself, 360-degree, as a specialist; do not push; do not cut the plan or the quality"). **Nothing in S3 is on live.** Evidence: `_evidence/s3-design-gate-checks-2026-11-03.md` and the matcher scoring below. The owner reviews at the end; every decision here names its reversibility so a wrong one is cheap to undo.

## Goal

One record per real-world organisation that shows **everything the company knows about it in one place**: the signals about it, the GTM account, the CRM account with its contacts, deals and notes. Today those are three tables that do not know about each other, and on live the CRM side is nearly empty (1 organisation) while Signals holds 60 companies and GTM 7 accounts, so the screen must be useful from Signals and GTM data first and let a person **create the CRM organisation from a signal company** in one step.

## Decisions (each with the six lenses; "door" = how hard it is to undo)

| # | Decision | User | Data | Security / legal | Operations / cost | Door | Why this and not the alternatives |
|---|---|---|---|---|---|---|---|
| D1 | **A thin identity table `g2g_org_accounts` with loose pointers** to `crm_organizations`, `gtm_accounts`, `g2g_companies`; no merge of the three | one place to look | nothing moves; pointers carry a *basis* (why they link) | no new personal data | one new table, no FKs, no live-table DDL | **two-way** (drop the table; nothing else points at it) | Rejected: bridge columns on all three tables (DDL on the core CRM table, three places to keep in step); merging into `crm_organizations` (13 tables hold real references to it: one-way) |
| D2 | **Auto-link only on exact evidence**: equal normalised name or equal domain, same organisation, **unambiguous** (one candidate each way), place does not conflict. Everything fuzzy is a **suggestion** a person confirms; a rejected suggestion is never offered again | a short, trustworthy "possible duplicates" list | measured on live (below): exact name found 6 of 6 known links, 0 false; domain alone only 4 of 6 | a wrong merge mixes two schools' histories and contacts: the costly error | no cost | two-way (links carry their basis; unlink is a row change) | Costs are asymmetric: a missed link costs a click, a false link costs trust. Fuzzy thresholds 0.5 to 0.9 added **nothing** on live (no near-misses), so they only feed the suggestion list |
| D3 | **Lifecycle** `discovered` (signal company only) → `target` (has a GTM account) → `engaged` (has a CRM organisation) → `customer` (set by a person or later by a won order) → `archived` | one word for where an organisation stands | derived by the backfill, editable | none | none | two-way | A computed "customer" from stage names would be a guess; it is set explicitly |
| D4 | **Create the CRM organisation from the identity** (one action, idempotent, needs the CRM add right). If a **deleted** CRM organisation has the same name, offer to **restore it** instead of failing; never change the unique index | the one-step path from signal to account | restoring keeps the old history | rights of the CRM screen are enforced through the write (no bypass) | no live DDL | two-way | Defect D10 (unique name counts deleted rows) is fixed by behaviour, not by dropping and re-creating a unique index on the core table (a one-way DDL on live for a rare case) |
| D5 | **Widen the four CRM email columns to 191** (defect D9) in place | none | stops silent truncation of long addresses at 100 characters | an address cut off is an address mis-stored | in-place on MariaDB (same length-prefix class) | one-way but lossless | live is not strict, so truncation is happening silently today |
| D6 | **Fix the organisation merge to repoint every table that references an organisation** (defect D10), from one reviewed registry, with a test that fails when a new reference column is not in it | merged duplicates keep their quotes, orders, invoices, tickets | no orphans | no data crosses organisations (registry is tenant-scoped) | one transaction | two-way (it only moves pointers) | Today it repoints 4 of 17 reference points; the rest stay on the deleted duplicate. Changing an existing endpoint's behaviour is deliberate and flagged here |
| D7 | **Read-only 360 aggregate**: capped lists (20 per section), no new writes except link confirm/reject, lifecycle/owner/name edit and create-CRM-organisation | all in one screen | no copying | contact details shown only to those with the screen's right; no export | queries are indexed (EXPLAIN checked) | two-way | Phone numbers and emails are shown only where the source screen already shows them |
| D8 | **Rights**: new menu leaf `/module/crm/organisation-360`, Administrator first, found by `access_link`, leaf-only grant; writes check the rights of the screens they write through | admin-only start | none | no bypass | none | two-way | Same pattern as the Product Master |
| D9 | **Nothing is created or changed in `crm_organizations`, `gtm_accounts`, `g2g_companies` by the backfill** | none | read-only on the three | none | dry run by default | two-way | The backfill only fills the new table |

## What the raw data said (decision D2) — verified read-only on live and dev

Matchers scored against the **links the system already holds** (`gtm_accounts.company_id`): live organisation 6, 7 accounts × 60 companies = 420 pairs, 6 already linked.

| matcher | live TP | FP | FN | precision [95% CI] | recall [95% CI] |
|---|---|---|---|---|---|
| exact domain | 4 | 0 | 2 | 1.00 [0.51, 1.00] | 0.67 [0.30, 0.90] |
| exact normalised name | 6 | 0 | 0 | 1.00 [0.61, 1.00] | 1.00 [0.61, 1.00] |
| domain OR exact name | 6 | 0 | 0 | 1.00 [0.61, 1.00] | 1.00 [0.61, 1.00] |
| name token overlap 0.5 … 0.9 | 6 | 0 | 0 | same | same |
| `similar_text` ≥ 85% | 6 | 0 | 0 | same | same |

Of the 6 linked pairs, domain **and** name agree on 4, only the name on 2 (no domain on one side). Dev: exact name 2 of 2 linked pairs plus one same-name pair with **no** link recorded, which the labels count as a false positive but is almost certainly a true match nobody linked: **the system's links give a lower bound on precision, not the truth.** The intervals are wide (n = 6), so this is *consistent with* the rule, not proof of it; the validator that settles it is a set of pairs labelled by the sales owner (below). One real duplicate exists **inside** the Signals companies on live (the same school twice).

## Validators that are neither the assistant nor the owner

1. **A person-labelled set.** `D:\dev\agentic-crm-research\s3-labelling-sheet.csv` (outside the repo: school names are business data) lists the candidate pairs with an empty "same organisation? yes / no" column for the sales owner to fill at review time; the matcher is re-scored against it and the rule changed if it fails.
2. **The production database as oracle.** Read-only invariant queries after any backfill: every pointer resolves to a live row of the same organisation; no source row is in two identities; no identity has no pointer; counts reconcile with the sources (`s3_invariants.php`).
3. **Instruments:** `EXPLAIN` on MariaDB 10.11 (dev) for every list/360 query and on live read-only for the queries over existing tables; axe-core (accessibility) in the browser walkthrough; an automated **cross-tenant sweep** that calls every id route of the new API with another organisation's ids and expects 404.
4. **A property-based merge test**: seeded random graphs of organisations and referencing rows over every table of the registry; after a merge no row references the removed organisation, no row is lost, and no other organisation's row moves.
5. **A reviewer who is not either of us:** Harshit or Rajesh on the pull request (the owner pushes at the end).

## Build contract

### Database (migrations written and tested already)

`2026_12_09_090000_create_g2g_org_accounts_tables` (`g2g_org_accounts`, `g2g_org_link_rejections`), `2026_12_09_090100_widen_crm_email_columns_to_191`. Menu row migration `2026_12_09_090200_create_organisation_360_menu_row` (track B1a).

### Backend (hp_erp)

Namespace `App\Domain\OrgAccounts`. Source types are the strings `crm_organization`, `gtm_account`, `g2g_company`.

- `OrgAccount` (Eloquent, soft deletes, table `g2g_org_accounts`).
- `OrgNormalizer::name(?string): string` (lower-case, punctuation to spaces, drop the words pvt ltd llp inc private limited the of and school schools, sorted-unique tokens joined by a space); `::domain(?string): ?string` (lower-case, strip protocol, `www.`, path, query); `::tokens(?string): list<string>`; `::overlap(array,array): float` (Jaccard).
- `OrgResolver::suggestions(int $tenantId, int $limit = 100): array` of `{key, left:{type,id,name}, right:{type,id,name}, basis:'name_overlap', score}` (overlap ≥ 0.8, not an exact match, not already in one identity, not rejected; never between two rows of the same source type except Signals companies, where it finds duplicates).
- `OrgLinker::confirm(int $tenantId, ?int $userId, string $leftType, int $leftId, string $rightType, int $rightId): array` (attaches the source rows to one identity, merging two identities when both exist; basis `manual`; transactional; tenant-checked) and `::reject(...)`.
- Backfill (`org-accounts:backfill {--tenant=} {--database=} {--apply} {--summary=}`; dry run by default; one transaction per organisation; idempotent; never overwrites an existing pointer): apply existing links first (`gtm_accounts.company_id`, `gtm_accounts.crm_organization_id`; basis `existing_link`), then exact name or domain, **unambiguous and place-guarded** (basis `exact_name` / `exact_domain`); one identity per connected component; name priority CRM > GTM > company; lifecycle derived (CRM → `engaged`, else GTM → `target`, else `discovered`). Prints counts per basis, ambiguous skipped, place-guard skipped, suggestions pending. Never writes to the three source tables.
- Command `org-accounts:grant --tenant= [--apply] [--revoke] [--database=]` (leaf only, administrators through `RoleKey::fromProfile`, copy `signals:grant-review`).
- API under `/api/org-accounts`, token + tenant from the token, routes file `routes/org_accounts.php` registered in `bootstrap/app.php` like `routes/product_master.php`; rights `platformright:/module/crm/organisation-360,{view|add|edit|delete}` per action; literal paths before `/{id}`; every `{id}` `whereNumber`; another organisation's id is **404**:
  - `GET /` list: `q`, `lifecycle`, `has_crm`, `has_gtm`, `has_signals` (0/1), `page`, `per_page` (≤ 50), `sort`. Returns `{status:1, data:[item], meta:{page,per_page,total,last_page}, summary:{total,discovered,target,engaged,customer,archived,with_crm,with_gtm,with_signals,suggestions}}`.
  - `GET /meta` → `{status:1,data:{lifecycles:[…], can:{create_crm_organization,edit,confirm_links}}}`.
  - `GET /suggestions` → `{status:1,data:[suggestion]}`.
  - `POST /links` `{left_type,left_id,right_type,right_id,action:'confirm'|'reject'}` (edit right) → `{status:1,data:item|null}`; 422 field-keyed; 404 cross-tenant.
  - `GET /{id}` the 360 aggregate (shape in `services/org-accounts/types.ts`); capped lists.
  - `PATCH /{id}` `{lifecycle?, owner_user_id?, name?}` (edit right; lifecycle one of the five; owner must be a user of the organisation).
  - `POST /{id}/create-crm-organisation` `{restore_deleted?: bool}` (add right on this screen **and** the CRM organisation add right): creates the CRM organisation from the identity, links it (`crm_link_basis=manual`), sets lifecycle `engaged` if lower; if the identity already has one: 200 `created:false`; if a deleted CRM organisation has the same name: 409 `deleted_name_exists` with `{deleted_organization_id}` unless `restore_deleted` is true, which restores it and links it.
- Merge fix (track B2): `OrganisationReferences` (the registry of every table and column, direct and polymorphic, that references a CRM organisation) and `OrganisationMerger::repoint(int $tenantId, int $fromId, int $toId)`; `CrmOrganizationController::merge`'s `repoint` closure calls it. A static test scans the migration files for any column named like an organisation reference and fails if it is neither in the registry nor in an explicit allow-list with a reason.

### Front end (g2gv0)

`app/module/crm/organisation-360/page.tsx` and `[id]/page.tsx` (copy the Product Master's), content-map entries for `/module/crm/organisation-360`; list on the scaffolds (search, Lifecycle facet, "Has CRM / GTM / Signals" facets, summary tiles, a **Possible duplicates** tab with Confirm and Not the same); the detail page with tabs Overview, Signals, Contacts, Deals, Activity and an action bar (Create CRM organisation, edit lifecycle and owner). Every state designed and tested: loading, empty, filtered-empty, error with retry, saving, conflict, forbidden. Types in `services/org-accounts/types.ts` (written with this file).

## Acceptance checks

1. Backfill on dev: exact-evidence links only; re-run changes nothing; no source table changed (checksum before and after); every pointer resolves; no source row in two identities.
2. Matcher re-scored on dev and live (read-only) with the same method and the owner's labelled pairs when supplied.
3. Cross-tenant sweep: every id route returns 404 for another organisation's ids; no list ever returns another organisation's row.
4. Property-based merge test passes over the registry (seeded, ≥ 200 graphs) and the static scan finds no unregistered reference column.
5. `confirm` of two identities merges them in one transaction; `reject` is never re-offered; `create-crm-organisation` is idempotent and restores a deleted same-name organisation only when asked.
6. `EXPLAIN` of the list, 360 and suggestion queries on dev shows an index on every filtered column (no full scan of a growing table).
7. Browser walk-through on dev (screenshots), axe-core clean at serious/critical, keyboard-only path to create a CRM organisation; phone width.
8. The email widening migration runs on dev; the four columns read `varchar(191)`.
9. Nothing on live. `git diff --stat components/ui/` empty. Full suites green apart from the known non-S3 failures.

## Tasks

| # | Task | Status |
|---|---|---|
| 0 | Facts, matcher scoring, decisions | **done 2026-11-03** |
| 1 | Schema migrations + test | **done 2026-11-03** (7 tests; a mutation caught) |
| 2 | Resolver, linker, backfill, commands, menu row (B1a) | **done 2026-11-03** (94 tests; commit 9b5bbe95) |
| 3 | API, rights, tenant sweep, EXPLAIN (B1b) | **done 2026-11-03** (47 tests, 11 mutations caught; commit 1b49b87f; EXPLAIN on dev run by me, evidence saved) |
| 4 | Merge fix and property-based test (B2) | **done 2026-11-03** (20 tests incl. 250 random worlds; 8 mutations caught; commit 4cf290e0; registry checked against the real dev schema and data) |
| 5 | Front end (F1) | **built 2026-11-03** (131 tests; eslint clean; commit 82a219a in g2gv0); real-browser check is task 7 |
| 6 | Dev: migrations one at a time, backfill dry run → apply → re-run, invariants, EXPLAIN | **done 2026-11-03** (3 migrations; 51 rows → 46 identities; re-run no-op; checksums identical; invariants 17 of 17; EXPLAIN clean at 58 rows) |
| 7 | Browser walk-through with axe-core | **running 2026-11-03** (agent af88142e6e2f313fd; see the time log) |
| 8 | Live rollout | **only on the owner's explicit go** |

## Risks

- Names collide across places ("Kendriya Vidyalaya"): the ambiguity rule and the place guard exist for this; the labelled set is the real test.
- Changing the merge touches an existing endpoint: covered by the property test and the existing CRM tests.
- The 360 aggregate joins many tables: capped lists and EXPLAIN keep it bounded; measured at 10× row counts in the walkthrough.

## Old screens

The CRM Organisations, GTM Accounts and Signals screens stay unchanged. Decided by the owner on: ______

## Work log (append-only)

- **2026-11-03 (night)** — Facts re-checked (`_evidence/s3-design-gate-checks-2026-11-03.md`); matchers scored on live and dev against the system's own links; nine decisions made with the six lenses; schema migrations and test written (7 tests, mutation caught); contract written; build tracks next.

- **2026-11-03 (afternoon)** — The three build tracks finished and were committed after I re-ran their tests myself (161 PHP tests, 131 front-end tests) and spot-checked two claims per track against the source and the real schema (verified: the CRM store route accepts exactly the two menu rows OrgAccess checks; all 31 registry columns exist on dev; no organisation-looking column or type/id pair outside the registry except the deliberate append-only history). Dev: migrations applied, backfill applied (51 source rows → 46 identities; existing_link 8, exact_name 1), re-run a no-op, source-table checksums identical, invariants 17 of 17 after correcting one mis-specified check (lone rows have no basis by design). EXPLAIN on dev: no scan/filesort on tables over 200 rows, no per-row query (but only 58 rows: this proves index use, not speed at scale). **Differences from the brief, for the owner:** merge keeps the older identity and MOVES the removed one's GTM/Signals pointers instead of nulling them; identical relationship edges are de-duplicated; NULL-tenant rows are never moved. Time log: `_evidence/time-log-2026-11-03.md`. Nothing is on live; nothing pushed.
