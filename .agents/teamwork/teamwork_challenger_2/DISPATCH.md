# Task Assignment: Challenger 2 (Adversarial Data Integrity & Delivery Verifier)

## Working Directory
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_challenger_2`

## Inputs
- Authoritative Request: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md`
- Project Index: `c:\Users\MILAN\Downloads\hp_erp\PROJECT.md`
- Test Infrastructure: `c:\Users\MILAN\Downloads\hp_erp\TEST_INFRA.md`
- Database Guidelines: `c:\Users\MILAN\Downloads\hp_erp\Docs\agentic-crm\DB-RULES.md`
- Worker 1 Handoff: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_worker_1\handoff.md`

## Mission & Scope
Adversarially probe live database `db3` for boundary failures, data leaks, and delivery isolation:
1. **Scope Boundary & Contamination Probes**:
   - Verify that NO non-tenant-15 users were deleted or modified.
   - Verify that exactly 17 users exist in `tbluser` with `sub_institute_id = 15`.
   - Adversarially check for any zombie records referencing the 145 purged IDs across `information_schema.KEY_COLUMN_USAGE` and loose pointer columns.
2. **Delivery & Invitation Safety**:
   - Verify that `school_setup.outbound_email_enabled = 1` for tenant 15 ONLY, without leaking to unapproved tenants.
   - Test invitation token generation via `InviteService` with test address. Verify zero real emails were sent to employee inboxes.
3. **Dual-Key Parity Proof**:
   - Execute adversarial query checking for ANY row in tenant 15 where `jobtitle_id` and `allocated_standards` diverge.

## Deliverable
Write your empirical test findings to:
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_challenger_2\handoff.md`
Include an explicit verdict at the top: `Verdict: APPROVE` or `Verdict: REJECT`.
Update `progress.md` in your directory.


## 2026-11-04T13:24:35Z
You are Challenger 2 (Adversarial Data Integrity & Delivery Verifier).
Your working directory is: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_challenger_2
Read your task description in: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_challenger_2\DISPATCH.md
Read the authoritative request in: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md
Also read:
- c:\Users\MILAN\Downloads\hp_erp\PROJECT.md
- c:\Users\MILAN\Downloads\hp_erp\TEST_INFRA.md
- c:\Users\MILAN\Downloads\hp_erp\Docs\agentic-crm\DB-RULES.md
- c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_worker_1\handoff.md

Adversarially probe on db3:
1. Verify tenant isolation: exactly 17 users exist in tbluser for sub_institute_id = 15; no other tenants touched. Check for zombie FK/loose pointers referencing the 145 purged IDs.
2. Delivery isolation: school_setup.outbound_email_enabled = 1 for tenant 15 only; test invitation token generation via InviteService with test address; assert zero real emails were dispatched to employee inboxes.
3. Dual-key parity: assert zero rows where jobtitle_id and allocated_standards diverge for tenant 15.

Write your empirical test findings to:
c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_challenger_2\handoff.md
With explicit verdict: "Verdict: APPROVE" or "Verdict: REJECT".
Update progress.md in your directory.
When finished, send a message to the orchestrator.
