# Task Assignment: Survey Explorer 2 (Catalogue & Role/Permission Specialist)

## Working Directory
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_2`

## Inputs
- Authoritative Request: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md`
- Source Spreadsheet: `C:\Users\MILAN\Downloads\Employee details -HRMS.xlsx` (Sheet "NAEMD")
- DB Guidelines: `c:\Users\MILAN\Downloads\hp_erp\Docs\agentic-crm\DB-RULES.md`

## Mission & Scope
Investigate the master catalogue, user job role schema, KASBA competency mapping, and role/permission administration:
1. Examine `s_jobrole` master catalogue on `db3`/`live` (or codebase models/migrations).
   - Confirm whether "Institute of Management Studies & Research" exists in `s_jobrole`.
   - Identify the standard sectors: `Training and Adult Education` (for academic roles) and `Business Management and Administration` (for administrative roles).
   - Find existing jobroles in these sectors matching the 12 distinct NAEMD designations.
2. Inspect `s_user_jobrole`, `s_user_jobrole_task`, `jobrole_competency_map`, and KASBA competency master tables.
   - Trace schema structure: columns, keys, and specifically `catalogue_jobrole_id` provenance.
   - Trace how tasks are linked (`s_user_jobrole_task`) and how competencies are linked in `jobrole_competency_map`.
   - Determine how tenant 15 currently has job roles set up and how the 12 NAEMD designations should be adopted/cloned.
3. Investigate Role and Permission Administration:
   - Identify the tables backing the UI: https://g2gv0.vercel.app/module/organizational-management/user-management/role-and-permissions (e.g., spatie permission tables, `tblroles`, `tbluser_role_mapping`, or custom tables).
   - Check what roles currently exist in tenant 15.
   - Determine how each user's assigned role should be updated to match their designation in the NAEMD spreadsheet.
4. Formulate the concrete adoption plan and SQL query templates for R3 and R5.

## Output Requirements
Produce a comprehensive handoff report at:
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_2\handoff.md`
Also update `progress.md` in your working directory.
When done, send a message to the orchestrator with your findings.


## 2026-11-04T12:38:48Z
[Message from 5b68fa18-feae-41b1-a429-5e267c9f9810]
You are Survey Explorer 2 (Catalogue & Role/Permission Specialist).
Your working directory is: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_2
Read your task description in: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_2\DISPATCH.md
Read the authoritative request in: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md
Also read:
- C:\Users\MILAN\Downloads\Employee details -HRMS.xlsx (Sheet "NAEMD")
- c:\Users\MILAN\Downloads\hp_erp\Docs\agentic-crm\DB-RULES.md

Your mission:
1. Examine s_jobrole master catalogue on db3/live. Confirm whether "Institute of Management Studies & Research" exists. Identify standard sectors: "Training and Adult Education" and "Business Management and Administration".
2. Match the 12 distinct NAEMD designations to master catalogue roles.
3. Inspect s_user_jobrole, s_user_jobrole_task, and jobrole_competency_map (and KASBA competency master tables), checking catalogue_jobrole_id provenance and cloning logic.
4. Investigate Role & Permission administration tables backing https://g2gv0.vercel.app/module/organizational-management/user-management/role-and-permissions. Check how each user's assigned role should match their designation in NAEMD sheet.
5. Provide concrete adoption SQL queries and role mapping templates.

Write your comprehensive report to:
c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_2\handoff.md
Update progress.md in your working directory.
When finished, send a message to the orchestrator summarizing your findings.
