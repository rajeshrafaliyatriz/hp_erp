# S4: Contact facts ledger and consent

**Status:** started 4 Nov 2026 (00:30 IST), backend track S4a dispatched from `../briefs/S4a-facts-backend.md`; front-end track S4b waits for the Organizations fold-in (track X) to be committed. Dev database only. Plan section: `../PLAN.md` S4. Nothing here sends anything.

## Goal

Facts about contacts, organisations and leads (an email, a phone, a website, a LinkedIn address) are PROPOSED with evidence, shown to a person, and accepted or dismissed. A human-entered value always wins; a dismissed value is never offered again; every sender will pass ONE consent gate. The rules come from Comp AI (MIT): fixed weights per evidence kind, a noisy-OR score, bands verified / probable / possible.

## Decisions (made by the assistant under the owner's "decide yourself, 360-degree view" instruction; each has its reason)

| # | Decision | Reason (lenses: user, data, security and legal, operations and cost, reversibility, time) | Door |
|---|---|---|---|
| D1 | Two new tables, `g2g_entity_facts` and `g2g_entity_fact_evidence`, loose pointers, no foreign keys, no JSON; weights live in `config/facts.php` | Data: one value exists once per entity and field (unique key), so "dismissed never re-offered" is a database rule. Ops: weights reviewable and testable in code. MariaDB 10.1 on live forbids JSON | two-way (tables are new) |
| D2 | Applying a fact writes the target column ONLY when the field is blank; otherwise it stays in the ledger | User and legal: a person's own entry must never be overwritten by a machine; reversible | two-way |
| D3 | No auto-apply in this slice: a proposal always waits for a person; an administrator-set autonomy level for facts arrives with the settings screen of S7 | The owner's default: autonomous actions only for notes and tasks | two-way |
| D4 | One `ConsentGate::allows(entity, channel)` reading `email_opt_out`, `crm_consent_preferences`, `gtm_suppressions` and `gtm_contacts.status`; the unsubscribe, complaint and bounce handlers write through so the systems agree | Legal: only `email_opt_out` is enforced today (defect D7 of the plan); sending stays off everywhere, so the gate is read-only until slice 10 | two-way |
| D5 | No new sidebar entry. Suggestions are a scope/tab inside the existing Contacts screen; the Evidence tab sits inside Account 360 on the Organization page (after track X is committed) and on the Contact detail | The owner's rule of 3 Nov: no duplicate screens | two-way |
| D6 | No LinkedIn fetching, ever (a static test asserts no request to linkedin.com); web-derived values are stored and rendered as text | Legal and security: untrusted input, terms of service | one-way (a policy) |

## Contract (backend, what track S4b builds against; change it here first)

Same as `../briefs/S4a-facts-backend.md` section "Contract": `GET /api/facts/entity/{type}/{id}`, `GET /api/facts/queue`, `POST /api/facts/{id}/accept`, `POST /api/facts/{id}/dismiss`, `GET /api/consent/{type}/{id}`; the Fact shape; rights through the existing Contacts and Organizations rows; another tenant's id answers 404.

## Tasks

| # | Task | Status |
|---|---|---|
| 1 | Read the originals, `SHOW CREATE TABLE` on dev, write the "verified" findings here | to do (S4a) |
| 2 | Migrations on dev, DB-CHANGELOG rows, evidence | to do (S4a) |
| 3 | `EvidenceScorer`, `IdentityMatcher`, `SocialUrl` with the ported golden tests | to do (S4a) |
| 4 | `FactWriter`, `SuggestionQueue`, API, tenant sweep, rights matrix, mutation proofs | to do (S4a) |
| 5 | `ConsentGate`, handlers write through, `LeadConverter` copies consent, `consent:reconcile` | to do (S4a) |
| 6 | Front end: Suggestions inside Contacts, Evidence tab on the Organization and Contact pages, consent line | to do (S4b, after X) |
| 7 | Real-browser check, full suites, commit, ledger, artifact | to do (caller) |

## Work log (append-only)

### 2026-11-04 00:30 — design and briefs written by the caller
Slice file and brief written from PLAN S4; the originals located (`D:\dev\crm\apps\agent`, MIT, 1,360 lines with tests). No code yet.
