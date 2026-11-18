# Task Assignment: Reviewer 2 (Catalogue, Roles & Delivery Reviewer)

## Working Directory
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_reviewer_2`

## Inputs
- Authoritative Request: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md`
- Project Index: `c:\Users\MILAN\Downloads\hp_erp\PROJECT.md`
- Test Infrastructure: `c:\Users\MILAN\Downloads\hp_erp\TEST_INFRA.md`
- Worker 1 Handoff: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_worker_1\handoff.md`
- Source Spreadsheet: `C:\Users\MILAN\Downloads\Employee details -HRMS.xlsx` (Sheet "NAEMD")

## Mission & Scope
Objectively and rigorously review catalogue adoption, KASBA competency mapping, role administration, and outbound delivery on `db3`:
1. **Catalogue Adoption & Provenance (R3)**:
   - Verify `s_jobrole` confirms "Institute of Management Studies & Research" does NOT exist in master catalogue.
   - Verify that 12 distinct NAEMD designations exist in `s_user_jobrole` for `sub_institute_id = 15` with valid `catalogue_jobrole_id` provenance pointing to standard sectors `Training and Adult Education` and `Business Management and Administration`.
   - Verify `s_user_jobrole_task` contains cloned tasks with valid `catalogue_task_id` provenance for all adopted roles.
   - Verify `jobrole_competency_map` maps competencies and KASBA items to the adopted roles.
   - Verify obsolete department placeholder roles (7300..7315) are completely removed.
2. **Role & Permission Administration (R5)**:
   - Verify `tbluser.user_profile_id` for each of the 16 NAEMD staff matches their spreadsheet designation (`Admin` 63 for Incharge 564, `Department Head` 60 for Faculty Coordinator 573, `Executive` 64 for Operations/Relations executives 567/569/570/572, `Employee` 58 for remaining 10).
3. **Outbound Delivery Activation (R4)**:
   - Verify `school_setup.outbound_email_enabled = 1` for tenant 15.
   - Verify `smtp_details` and `sms_api_details` are configured for `sub_institute_id = 15`.

## Deliverable
Write your review report to:
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_reviewer_2\handoff.md`
Include an explicit verdict at the top: `Verdict: APPROVE` or `Verdict: REQUEST_CHANGES`.
Update `progress.md` in your directory.
When finished, send a message to the orchestrator.


## 2026-11-04T13:24:19Z
[Message] sender=5b68fa18-feae-41b1-a429-5e267c9f9810
You are Reviewer 2 (Catalogue, Roles & Delivery Reviewer).
Your working directory is: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_reviewer_2
Read your task description in: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_reviewer_2\DISPATCH.md
Read the authoritative request in: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md
Also read:
- c:\Users\MILAN\Downloads\hp_erp\PROJECT.md
- c:\Users\MILAN\Downloads\hp_erp\TEST_INFRA.md
- c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_worker_1\handoff.md
- C:\Users\MILAN\Downloads\Employee details -HRMS.xlsx (Sheet "NAEMD")

Verify on db3:
1. s_jobrole confirms "Institute of Management Studies & Research" does not exist; 12 distinct NAEMD designations exist in s_user_jobrole for tenant 15 with valid catalogue_jobrole_id pointing to standard sectors TAE and BMA.
2. s_user_jobrole_task has cloned tasks with catalogue_task_id provenance.
3. jobrole_competency_map has mapped competencies and KASBA items.
4. tbluser.user_profile_id matches NAEMD sheet designations.
5. school_setup.outbound_email_enabled = 1, smtp_details and sms_api_details configured for tenant 15.

Write your report to:
c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_reviewer_2\handoff.md
With explicit verdict: "Verdict: APPROVE" or "Verdict: REQUEST_CHANGES".
Update progress.md in your directory.
When finished, send a message to the orchestrator.
