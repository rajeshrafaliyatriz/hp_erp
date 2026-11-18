# Task Assignment: Worker 1 (Tenant 15 Reconciliation & Delivery Activation)

## Working Directory
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_worker_1`

## Mandatory Integrity Warning
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

## Inputs
- Authoritative Request: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md`
- Project Index: `c:\Users\MILAN\Downloads\hp_erp\PROJECT.md`
- Test Infrastructure: `c:\Users\MILAN\Downloads\hp_erp\TEST_INFRA.md`
- Database Guidelines: `c:\Users\MILAN\Downloads\hp_erp\Docs\agentic-crm\DB-RULES.md`
- Survey 1 Report: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_1\handoff.md`
- Survey 2 Report: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_2\handoff.md`
- Survey 3 Report: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_3\handoff.md`

## Mission & Implementation Steps
Execute the complete tenant reconciliation and delivery activation for tenant "Future Varsity" (`sub_institute_id = 15`) on database connection `db3` (`128.199.17.97`, database `hp_erp`), strictly following `DB-RULES.md` (transaction-guarded, backup tables, reversal SQL generation):

### Step 1: Catalogue Adoption & KASBA/Task Population (R3)
- Verify `s_jobrole` confirms "Institute of Management Studies & Research" does not exist in master catalogue.
- Insert the 12 distinct NAEMD designations into `s_user_jobrole` for `sub_institute_id = 15` with valid `catalogue_jobrole_id` provenance pointing to standard sectors `Training and Adult Education` and `Business Management and Administration` (as detailed in Survey 2 report).
- Clone corresponding tasks from `s_jobrole_task` into `s_user_jobrole_task` with `catalogue_task_id` provenance.
- Seed institutional competencies and KASBA items into `competency` and `competency_kasba_item` for tenant 15, and link them to adopted roles in `jobrole_competency_map`.

### Step 2: Employee Scope Reduction & Pre-Purge Backup (R1)
- Create backup table `tbluser_backup_tenant15_purge_20261104` LIKE `tbluser`.
- Insert the 145 provisional users (IDs between 547..563 and 580..707) into the backup table.
- Delete the 145 provisional users from `tbluser` where `sub_institute_id = 15`.
- Assert exactly 17 users remain in `tbluser` for tenant 15 (Admin 479 + 16 NAEMD staff IDs 564..579).
- Run orphan check queries across dependent tables (`s_skill_matrix`, `hrms_attendances`, `calendar_events`, `staff_document`, etc.) asserting zero orphans.
- Create reversal SQL script at `database/reconciliation_tenant15/reversal_purge.sql` or equivalent.

### Step 3: Directory Dual-Key Sync & Role Administration (R2 & R5)
- Synchronize `tbluser.allocated_standards = CAST(jobtitle_id AS CHAR)` where `sub_institute_id = 15` for the 16 NAEMD staff, pointing to their newly adopted `s_user_jobrole.id`.
- Update `tbluser.user_profile_id` to match their NAEMD designation (`Admin` 63 for Incharge 564, `Department Head` 60 for Faculty Coordinator 573, `Executive` 64 for Operations/Relations executives 567, 569, 570, 572, `Employee` 58 for Counsellors and Support Staff).
- Clean up obsolete department placeholder roles (IDs 7300..7315) from `s_user_jobrole`.
- Verify zero mismatches between `jobtitle_id` and `allocated_standards`.

### Step 4: Outbound Delivery Activation & Credential Invitation Verification (R4)
- Update `school_setup` setting `outbound_email_enabled = 1` for `id = 15`.
- Configure `smtp_details` and `sms_api_details` for `sub_institute_id = 15` cloning active platform standard configuration (`trizinnovation2018@gmail.com`, `smtp.gmail.com:465`, encrypted password `"okesecjnhbyiysmk"`, and SMS API).
- Test credential invitation flow using `InviteService::issue` with a designated test email address (or payload interception) to verify token creation and delivery flow WITHOUT sending live emails to employee inboxes.

### Step 5: Directory & Profile Endpoints Verification (R2 & R6)
- Test legacy Blade view and Next.js `/api/employees-management` endpoints.
- Confirm zero `Attempt to read property "jobrole" on null` crashes and zero 500 errors.
- Confirm HTTP 200 with populated job role data.

### Step 6: Artifacts & Reporting
- Generate and save all SQL migration and reversal scripts to disk.
- Run complete proof queries and output results.
- Write comprehensive handoff report to `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_worker_1\handoff.md`.

## 2026-11-04T12:59:54Z
Received from orchestrator:
Execute the full reconciliation on db3 (128.199.17.97, database hp_erp, MariaDB 10.1):
1. Adopt 12 distinct NAEMD designations in s_user_jobrole with catalogue_jobrole_id provenance; clone tasks to s_user_jobrole_task; link competencies in jobrole_competency_map.
2. Back up 145 provisional users to tbluser_backup_tenant15_purge_20261104; delete them from tbluser; assert exactly 17 users remain; verify 0 orphan records; generate reversal SQL scripts.
3. Synchronize tbluser.allocated_standards = CAST(jobtitle_id AS CHAR) for all 16 staff; update user_profile_id per NAEMD spreadsheet; delete obsolete department roles 7300..7315.
4. Enable school_setup.outbound_email_enabled = 1; configure smtp_details and sms_api_details for tenant 15; test invitation flow using a designated test email address without emailing real inboxes.
5. Verify directory endpoints return HTTP 200 with zero 500 errors and populated job role data.

## 2026-11-04T13:05:44Z
Received from orchestrator:
Priority directive from user to expedite execution. User has requested: "Speed up the work. Expedite the remaining execution stages (DB commit, dual-key sync, catalogue & KASBA adoption, delivery activation, and verification) and drive directly to completion without unnecessary delays, while ensuring strict compliance with transactional safety and DB-RULES.md."
Action: Please execute the validated SQL and script operations directly, verify with proof queries, and deliver your handoff promptly.


