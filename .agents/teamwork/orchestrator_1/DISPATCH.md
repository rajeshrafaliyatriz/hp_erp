# Dispatch History

## 2026-11-04T12:36:59Z
Perform a complete, audited tenant reconciliation and delivery activation for tenant "Future Varsity" (sub_institute_id = 15) on live database connection db3.

Working directory: c:\Users\MILAN\Downloads\hp_erp
Integrity mode: development

## Verification Resources
- Source spreadsheet: "C:\Users\MILAN\Downloads\Employee details -HRMS.xlsx" (Sheet "NAEMD" containing the 16 target employees)
- Database guidelines: Docs/agentic-crm/DB-RULES.md (transactional, guarded, generate reversal SQL)
- Live database connection: `live` or `db3` (128.199.17.97, database `hp_erp`, MariaDB 10.1)
- Target tenant ID: sub_institute_id = 15 (Future Varsity / Institute of Management Studies & Research); Primary admin user: id = 479 (lsi.mkes@futurevarsity.edu.in)
- Verification scripts: No pre-existing test suites; the team must author and run the SQL proof queries and HTTP verification requests.

## Requirements

### R1. Employee Scope Reduction (NAEMD Only)
Purge all 145 provisional employees imported from "All Verticals", retaining ONLY the 16 verified employees from sheet "NAEMD" plus the primary tenant admin (user ID 479). Ensure zero orphan records in dependent event and skill tables before committing the deletion, strictly following Docs/agentic-crm/DB-RULES.md with transaction guards and generated reversal SQL.

### R2. Fix Employee Directory "jobrole on null" Crash
Synchronize the dual-key legacy columns: ensure `tbluser.allocated_standards` is explicitly set to the corresponding `jobtitle_id` for all 16 employees, resolving the `Attempt to read property "jobrole" on null` error on edit/view screens. Verify that both the legacy Blade views and Next.js /api/employees-management endpoints return HTTP 200 with populated job role data.

### R3. Catalogue Adoption & KASBA/Task Population
Confirm that "Institute of Management Studies & Research" does not exist in the master `s_jobrole` catalogue, and map the tenant's required roles to standard sectors (`Training and Adult Education` for academic roles, `Business Management and Administration` for administrative roles). Adopt the 12 distinct NAEMD designations from the master catalogue into `s_user_jobrole` with `catalogue_jobrole_id` provenance. Clone the corresponding catalogue tasks into `s_user_jobrole_task` and link the KASBA competencies in `jobrole_competency_map`.

### R4. Outbound Delivery Activation & Credential Onboarding
Enable outbound email delivery in `school_setup` (`outbound_email_enabled = 1`) for tenant ID 15. Configure the tenant delivery profile in `smtp_details` / `sms_api_details` to support the G2G delivery settings (https://g2gv0.vercel.app/settings?s=delivery) by inheriting/cloning the platform's active standard configuration for tenant 15. Test and verify the credential invitation flow with a designated test email address / payload verification so credentials can be generated and sent without emailing the real employee inboxes directly.

### R5. Role and Permission Administration
Update employee administration in role and permission (https://g2gv0.vercel.app/module/organizational-management/user-management/role-and-permissions) so that each user's assigned role matches their designation in the NAEMD spreadsheet.

### R6. Automated Verification and Proof Output
Execute and output proof queries demonstrating that all reconciliation criteria are met, and ensure zero 500 errors when requesting employee profiles.

## 2026-11-04T13:05:05Z
PRIORITY DIRECTIVE FROM USER: "Speed up the work."
Expedite the remaining execution stages (DB commit, dual-key sync, catalogue & KASBA adoption, delivery activation, and verification) and drive directly to completion without unnecessary delays, while ensuring strict compliance with transactional safety and DB-RULES.md.
