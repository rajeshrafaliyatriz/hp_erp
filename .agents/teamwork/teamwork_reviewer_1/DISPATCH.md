# Task Assignment: Reviewer 1 (Schema & DB Reconciliation Reviewer)

## Working Directory
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_reviewer_1`

## Inputs
- Authoritative Request: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md`
- Project Index: `c:\Users\MILAN\Downloads\hp_erp\PROJECT.md`
- Test Infrastructure: `c:\Users\MILAN\Downloads\hp_erp\TEST_INFRA.md`
- Database Guidelines: `c:\Users\MILAN\Downloads\hp_erp\Docs\agentic-crm\DB-RULES.md`
- Worker 1 Handoff: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_worker_1\handoff.md`
- Migration & Verification Artifacts: `database/reconciliation_tenant15/`

## Mission & Scope
Objectively and rigorously review the database reconciliation state on live database `db3` (`128.199.17.97`, database `hp_erp`):
1. **Employee Scope & Cleanliness (R1)**:
   - Query `tbluser` where `sub_institute_id = 15`. Verify exactly 17 users exist: Primary Admin ID 479 + exactly 16 NAEMD staff (IDs 564..579).
   - Verify backup table `tbluser_backup_tenant15_purge_20261104` exists and contains the 145 purged provisional users.
   - Run orphan checks across dependent tables (`s_skill_matrix`, `hrms_attendances`, `calendar_events`, `staff_document`, etc.) to confirm 0 orphan records.
2. **Directory Dual-Key Synchronization (R2)**:
   - Verify `tbluser.allocated_standards = CAST(jobtitle_id AS CHAR)` for all 16 retained employees. Confirm 0 mismatches and 0 NULL values.
3. **Reversal Artifacts**:
   - Inspect `database/reconciliation_tenant15/reversal_all.sql`. Confirm it is syntactically valid and transactional.

## Deliverable
Write your review report to:
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_reviewer_1\handoff.md`
Include an explicit verdict at the top: `Verdict: APPROVE` or `Verdict: REQUEST_CHANGES`.
Update `progress.md` in your directory.
When finished, send a message to the orchestrator.


## 2026-11-04T13:24:11Z
You are Reviewer 1 (Schema & DB Reconciliation Reviewer).
Your working directory is: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_reviewer_1
Read your task description in: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_reviewer_1\DISPATCH.md
Read the authoritative request in: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md
Also read:
- c:\Users\MILAN\Downloads\hp_erp\PROJECT.md
- c:\Users\MILAN\Downloads\hp_erp\TEST_INFRA.md
- c:\Users\MILAN\Downloads\hp_erp\Docs\agentic-crm\DB-RULES.md
- c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_worker_1\handoff.md
- database/reconciliation_tenant15/

Verify:
1. Exactly 17 users exist in tbluser for sub_institute_id = 15 (Admin 479 + 16 NAEMD staff 564..579).
2. tbluser_backup_tenant15_purge_20261104 exists with 145 users.
3. 0 orphan records in dependent tables.
4. tbluser.allocated_standards = CAST(jobtitle_id AS CHAR) for all 16 staff with 0 mismatches.
5. database/reconciliation_tenant15/reversal_all.sql is valid.

Write your report to:
c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_reviewer_1\handoff.md
With explicit verdict: "Verdict: APPROVE" or "Verdict: REQUEST_CHANGES".
Update progress.md in your directory.
When finished, send a message to the orchestrator.
