# Task Assignment: Forensic Auditor (Integrity Forensics)

## Working Directory
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_auditor_1`

## Inputs
- Authoritative Request: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md`
- Project Index: `c:\Users\MILAN\Downloads\hp_erp\PROJECT.md`
- Test Infrastructure: `c:\Users\MILAN\Downloads\hp_erp\TEST_INFRA.md`
- Database Guidelines: `c:\Users\MILAN\Downloads\hp_erp\Docs\agentic-crm\DB-RULES.md`
- Worker 1 Handoff: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_worker_1\handoff.md`
- Migration & Verification Artifacts: `database/reconciliation_tenant15/`

## Mission & Scope
Conduct an independent forensic integrity audit of the reconciliation artifacts and live database mutations:
1. **Anti-Cheating & Integrity Verification**:
   - Check if any test results, query outputs, or verification assertions are hardcoded, mocked, or fabricated.
   - Verify that all database mutations were genuinely committed to `db3` (`128.199.17.97`, MariaDB 10.1.48, database `hp_erp`) by querying the database directly.
2. **Schema & Data Authenticity**:
   - Confirm `tbluser` contains exactly 17 genuine user rows for `sub_institute_id = 15`.
   - Confirm `tbluser_backup_tenant15_purge_20261104` contains the exact 145 purged rows.
   - Confirm `s_user_jobrole` holds the 12 adopted NAEMD designations with valid `catalogue_jobrole_id` provenance.
   - Confirm `s_user_jobrole_task` holds genuine cloned tasks with `catalogue_task_id` provenance.
   - Confirm `jobrole_competency_map` holds genuine competency mappings.
   - Confirm `school_setup.outbound_email_enabled` is genuinely 1 and SMTP/SMS settings are populated.
3. **Audit Verdict**:
   - If ANY fabricated results, fake logs, or mock bypasses are detected -> report `Verdict: INTEGRITY VIOLATION`.
   - If all implementations and database states are genuine and verified -> report `Verdict: CLEAN`.

## Deliverable
Write your forensic audit report to:
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_auditor_1\handoff.md`
Include the prominent binary verdict: `Verdict: CLEAN` or `Verdict: INTEGRITY VIOLATION`.
Update `progress.md` in your directory.
When finished, send a message to the orchestrator.


## 2026-11-04T13:24:46Z
[Message] timestamp=2026-11-04T13:24:46Z sender=5b68fa18-feae-41b1-a429-5e267c9f9810 priority=MESSAGE_PRIORITY_HIGH content=You are the Forensic Auditor (teamwork_auditor).
Your working directory is: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_auditor_1
Read your task description in: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_auditor_1\DISPATCH.md
Read the authoritative request in: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md
Also read:
- c:\Users\MILAN\Downloads\hp_erp\PROJECT.md
- c:\Users\MILAN\Downloads\hp_erp\TEST_INFRA.md
- c:\Users\MILAN\Downloads\hp_erp\Docs\agentic-crm\DB-RULES.md
- c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_worker_1\handoff.md
- database/reconciliation_tenant15/

Perform an independent forensic integrity audit on live database db3 (128.199.17.97, MariaDB 10.1.48, database hp_erp):
1. Anti-Cheating & Integrity Verification:
   - Check if any test results, query outputs, or assertions are hardcoded, mocked, or fabricated.
   - Verify that all database mutations were genuinely committed to db3 by querying the database directly.
2. Verify Schema & Data Authenticity:
   - tbluser contains exactly 17 genuine user rows for sub_institute_id = 15.
   - tbluser_backup_tenant15_purge_20261104 contains the 145 purged rows.
   - s_user_jobrole holds the 12 adopted NAEMD designations with valid catalogue_jobrole_id provenance.
   - s_user_jobrole_task holds genuine cloned tasks with catalogue_task_id provenance.
   - jobrole_competency_map holds genuine competency mappings.
   - school_setup.outbound_email_enabled is genuinely 1 and SMTP/SMS settings are populated.
3. Binary Audit Verdict:
   - If ANY fabricated results, fake logs, or mock bypasses are detected -> report "Verdict: INTEGRITY VIOLATION".
   - If all implementations and database states are genuine and verified -> report "Verdict: CLEAN".

Write your report to:
c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_auditor_1\handoff.md
Update progress.md in your directory.
When finished, send a message to the orchestrator.
