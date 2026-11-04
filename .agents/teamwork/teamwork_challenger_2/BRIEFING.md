# BRIEFING — 2026-11-04T13:25:00Z

## Mission
Adversarially probe live database db3 for tenant isolation, data integrity, delivery isolation, and dual-key parity following Worker 1's tenant 15 onboarding.

## 🔒 My Identity
- Archetype: EMPIRICAL CHALLENGER
- Roles: critic, specialist
- Working directory: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_challenger_2
- Original parent: 5b68fa18-feae-41b1-a429-5e267c9f9810
- Milestone: Adversarial Data Integrity & Delivery Verification
- Instance: 1 of 1

## 🔒 Key Constraints
- Review-only & Empirical verification — do NOT modify implementation code or live data except controlled test harness calls with immediate rollback/cleanup.
- Must run verification code directly; do NOT trust worker's claims or logs.
- .agents/teamwork/ holds ONLY agent metadata — NEVER place source code, tests, or data files here.
- Explicit verdict required: "Verdict: APPROVE" or "Verdict: REJECT".

## Current Parent
- Conversation ID: 5b68fa18-feae-41b1-a429-5e267c9f9810
- Updated: 2026-11-04T13:25:00Z

## Review Scope
- **Files to review**:
  - `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md`
  - `c:\Users\MILAN\Downloads\hp_erp\PROJECT.md`
  - `c:\Users\MILAN\Downloads\hp_erp\TEST_INFRA.md`
  - `c:\Users\MILAN\Downloads\hp_erp\Docs\agentic-crm\DB-RULES.md`
  - `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_worker_1\handoff.md`
- **Target database**: `db3`
- **Verification criteria**:
  1. Tenant isolation: exactly 17 users in tbluser for sub_institute_id = 15; no other tenants touched; no zombie FK/loose pointers referencing 145 purged IDs.
  2. Delivery isolation: school_setup.outbound_email_enabled = 1 for tenant 15 only; test invitation token generation via InviteService; zero real emails dispatched.
  3. Dual-key parity: zero rows where jobtitle_id and allocated_standards diverge for tenant 15.

## Key Decisions Made
- [Initial turn initialization]

## Artifact Index
- `DISPATCH.md` — Task assignment & instructions
- `progress.md` — Liveness & status tracking
- `handoff.md` — Final 5-component handoff report

## Attack Surface
- **Hypotheses tested**: TBD
- **Vulnerabilities found**: TBD
- **Untested angles**: All target probes (tenant isolation, delivery isolation, dual-key parity)

## Loaded Skills
- None requested in task dispatch.
