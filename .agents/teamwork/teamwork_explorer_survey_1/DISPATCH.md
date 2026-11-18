# Task Assignment: Survey Explorer 1 (Data & DB Schema Specialist)

## Working Directory
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_1`

## Inputs
- Authoritative Request: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md`
- DB Guidelines: `c:\Users\MILAN\Downloads\hp_erp\Docs\agentic-crm\DB-RULES.md`
- Source Spreadsheet: `C:\Users\MILAN\Downloads\Employee details -HRMS.xlsx` (Sheet "NAEMD")

## Mission & Scope
Investigate the data, database schema, and live DB connection details for tenant 15 (Future Varsity / Institute of Management Studies & Research):
1. Read `Docs/agentic-crm/DB-RULES.md` carefully and document the rules for transactions, guard clauses, table backup, and rollback/reversal SQL generation.
2. Read the source spreadsheet `C:\Users\MILAN\Downloads\Employee details -HRMS.xlsx` sheet `NAEMD`. Extract and list all 16 employees (name, designation, email, employee code/id if any, and any mapped identifiers).
3. Check the database connection configuration in `c:\Users\MILAN\Downloads\hp_erp` (e.g. `.env`, `config/database.php`) for `live` / `db3` (128.199.17.97, database `hp_erp`, MariaDB 10.1). How can SQL queries and scripts be run safely (e.g. through php artisan or python or mysql cli)?
4. Inspect `tbluser` and all related/dependent tables for tenant `sub_institute_id = 15`.
   - Identify primary admin user (id = 479, `lsi.mkes@futurevarsity.edu.in`).
   - Identify the 145 provisional employees imported from "All Verticals" vs the 16 NAEMD verified employees.
   - Trace all foreign keys and dependent event, skill, attendance, and log tables referencing `tbluser` for these users.
   - Check the dual-key legacy columns: `tbluser.allocated_standards` and `tbluser.jobtitle_id`. Check current mismatch state.
5. Provide precise recommendations and SQL query drafts for:
   - Purging provisional users safely with zero orphans.
   - Generating rollback / reversal SQL.
   - Synchronizing `allocated_standards` = `jobtitle_id`.

## Output Requirements
Produce a comprehensive handoff report at:
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_1\handoff.md`
Also update `progress.md` in your working directory.


## 2026-11-04T12:38:41Z
You are Survey Explorer 1 (Data & DB Schema Specialist).
Your working directory is: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_1
Read your task description in: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_1\DISPATCH.md
Read the authoritative request in: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md
Also read:
- c:\Users\MILAN\Downloads\hp_erp\Docs\agentic-crm\DB-RULES.md
- C:\Users\MILAN\Downloads\Employee details -HRMS.xlsx (Sheet "NAEMD")

Your mission:
1. Document DB transaction & safety rules from DB-RULES.md (reversal SQL, guards).
2. Extract and list all 16 employees from "NAEMD" sheet.
3. Check db3 / live DB connection in hp_erp codebase.
4. Inspect tbluser and all dependent tables (skills, events, attendance, etc.) for tenant 15 (admin 479 vs 145 provisional vs 16 NAEMD staff).
5. Check allocated_standards vs jobtitle_id dual-key sync state.
6. Provide concrete analysis, queries, and orphan-check SQL.

Write your comprehensive report to:
c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_1\handoff.md
Update progress.md in your working directory.
When finished, send a message to the orchestrator summarizing your findings.
