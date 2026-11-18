# Track S4a: contact facts ledger and consent, BACKEND (brief for a build agent)

Read `COMMON-RULES.md` first. Plan: `../PLAN.md` section 7, slice S4 (read it in full: it is the specification), `../DB-RULES.md` (every database change), `../SESSION-RULES.md`, `../DECISIONS.md` (the "no duplicate screens" rule, the owner's standing instructions). Written 4 Nov 2026 (00:25 IST) so a replacement can continue from the files after a usage limit.

## Goal

A ledger of FACTS about contacts, organisations and leads, each with its EVIDENCE, a score and a band, that an agent or a person can PROPOSE and a person ACCEPTS or DISMISSES; a dismissed value is never offered again; a human-entered value is never overwritten; plus ONE consent gate that every future sender must pass. Comp AI's evidence and identity rules (MIT licence) are ported to PHP. Nothing here sends anything.

## Raw material (read these yourself, do not rely on this brief for the numbers)

The originals, MIT licensed (`D:\dev\crm\LICENSE`): `D:\dev\crm\apps\agent\agent\lib\evidence.ts` (164 lines: evidence kinds, WEIGHTS, noisy-OR score, bands), `people.ts` (357), `socials.ts` (418), and their tests `D:\dev\crm\apps\agent\test\identity.spec.ts`, `people.spec.ts`, `socials.spec.ts`. Port the LOGIC and the TEST CASES to PHP; do not translate blindly: read each case, understand what rule it pins, and write the PHPUnit case from the rule. Where the original does something G2G cannot (it verifies people it already has; it uses LinkedIn lookups), leave it out and say so. Put the weights in `config/facts.php`, not in the database. A static test asserts that no code in `app/Domain/Facts` requests linkedin.com.

Real data to look at before designing (read-only, dev database only, connection `mysql`): `SHOW CREATE TABLE crm_contacts, crm_organizations, crm_consent_preferences, gtm_contacts, gtm_suppressions`; row counts per tenant for tenant 6; how `email_opt_out` is written and read today (`grep -rn email_opt_out app`); the unsubscribe, complaint and bounce handlers (`CrmOutreachMailer`, `OutreachMailer`, GTM suppressions); `LeadConverter` (consent is dropped on conversion). Record what you find in the slice file as `verified`.

## Contract (backend side; the front-end track S4b builds against it)

All routes: `api` + `api.token`, rights through `platformright` on the EXISTING screens (no new menu row): reading a contact's or an organisation's facts needs view on the Contacts row (`/module/crm/marketing/contacts`) or the Organizations rows (`/module/crm/marketing/organizations` or `/module/crm/support/organizations`, accepted as `|` like `OrgAccess`); accept and dismiss need edit on the same. Every by-id read filters by tenant and answers another tenant's id with the same 404. Numeric ids only. Registered bare in `bootstrap/app.php` (own prefix), like `routes/agent_gateway.php`.

- `GET /api/facts/entity/{type}/{id}` where type is `contact`, `organization` or `lead`: `{status:1, data:{entity:{type,id,name}, facts:[Fact...], counts:{proposed,applied,dismissed}}}`.
- `GET /api/facts/queue?type=&status=proposed&page=&per_page<=50`: the tenant's suggestion queue, newest first, each Fact with its entity's name; `data:{rows, pagination:{page,per_page,total}}`.
- `POST /api/facts/{id}/accept` and `POST /api/facts/{id}/dismiss` (body `{reason?: string<=500}`): accept applies the fact (writes the target column when the field has one and the field is BLANK, else keeps it in the ledger only), exactly once (claim with a conditional UPDATE like `ProposalApplier`); dismiss keeps the value in the unique index so it is never re-offered. Answers: 200 with the Fact as it now stands; 409 `{code:'not_pending', fact}` when it was already decided; 404 as above.
- `GET /api/consent/{type}/{id}`: `{status:1, data:{channels:{email:{allowed:bool, reason:string|null}, sms:{...}, whatsapp:{...}}, sources:[{source,value,at}]}}`, reading through `ConsentGate`.
- Fact: `{id, entity_type, entity_id, field, value, value_kind:'personal'|'role'|null, status:'proposed'|'applied'|'dismissed'|'superseded', band:'verified'|'probable'|'possible', score:0..1, method, source_url, proposed_by, decided_by_name, decided_at, evidence:[{kind, label, weight, source_url, quote, retrieved_at}]}`. Labels are plain English from the weights table. A `value` that came from the web is DATA: store and return it as text (the screens render it as text).

## Database (additive only; follow DB-RULES in full; MariaDB 10.1 on live: no JSON column, raw guarded DDL like the agent migrations `2026_12_10_0900*`)

New migration files sort after `2026_12_10_120000_...`; name them `2026_12_11_09xxxx_...`. Tables exactly as PLAN S4: `g2g_entity_facts` (unique `(sub_institute_id, entity_type, entity_id, field, value_hash)`), `g2g_entity_fact_evidence`; `crm_contacts` and `crm_organizations`: `email` / `secondary_email` widened to varchar(191) ONLY if `SHOW COLUMNS` on dev says they are still 100 (S3 already widened some: check, do not repeat), `linkedin_url` varchar(300) NULL on both; use `crm_consent_preferences` if it exists (read its real columns first) else a guarded create. A migration is not a schema: read `SHOW COLUMNS` on dev. No live run (queue every live step in `../HANDOVER-4NOV.md`). Dev: apply each migration alone with `--path=`, save `SHOW CREATE TABLE` to `_evidence/`, add a row to `DB-CHANGELOG.md`.

## Code (hp_erp, namespace `App\Domain\Facts`)

`EvidenceScorer`, `IdentityMatcher`, `SocialUrl`, `FactWriter` (the ONE write path: ledger row, evidence rows, events, target column write-through only on a blank field, never overwrite a value a human entered), `SuggestionQueue`, `ConsentGate::allows(string $entityType, int $entityId, string $channel): array{allowed:bool, reason:?string}` reading `email_opt_out`, `crm_consent_preferences`, `gtm_suppressions` and `gtm_contacts.status`; unsubscribe, complaint and bounce handlers write through so both systems agree (touch the existing handlers minimally, with tests); `LeadConverter` copies consent to the contact; a reconciliation command `consent:reconcile --tenant= [--apply]` (dry run by default). Register events (`fact.proposed`, `fact.decided`) in `EventCatalogue` with idempotency keys carrying the fact id. A tool `propose_fact` for the agent gateway is NOT part of this track (S7 owns the registry); expose `FactWriter::propose(...)` for it.

## Tests and proofs (COMMON-RULES section 4 applies)

Golden tests ported from the three spec files (same cases, written as rules); score and band boundaries; "human value wins"; "dismissed never re-offered" (the unique key, plus a test that a second proposal of a dismissed value is refused with a reason); accept applies once under a race (see `AgentApplierClaimTest`); write-through only to a blank field; tenant sweep over every id route (read the routes from the router, see `AgentGatewayTenantSweepTest`); rights matrix; consent gate truth table over the four sources; migrations up, down, twice. Mutation proofs: break each safeguard on purpose, the named test must fail, restore byte for byte (a runner template is in the scratchpad notes of track Z: `D:\dev\agentic-crm-research\scripts\mutation\s7-agent\run-mutations.cjs`). Run only your own files plus `PhpFilesParseTest`, `NoByteOrderMarkTest`, `OrganisationReferencesScanTest` (every new table that points at a contact or organisation must be listed or allow-listed with a reason there, and merging two organisations or contacts must keep facts pointing at the survivor: extend `OrganisationReferences`/the merger for `g2g_entity_facts` and test it). Never the full suite; the caller runs it once.

## Do not

Touch `components/`, `app/` of g2gv0, `app/Domain/OrgAccounts/*`, `app/Domain/AgentGateway/*` (read them), the Signals design or any Ingestion or Partner file. No push, no commit (the caller commits), no live database, no message of any kind, no new sidebar entry. Hold the S4b track's contract: if you must change it, change the slice file `../slices/S04-contact-facts-and-consent.md` section "Contract" first and say so in your report.

## Report (COMMON-RULES section 7)

Files added or changed, design as built and deviations from the plan with reasons, tests and mutation results with the test that killed each, evidence paths, open defects with file:line, DB-CHANGELOG rows, what you could not verify, what the caller must do next. Keep `../slices/S04-contact-facts-and-consent.md` current as you work (append-only work log).
