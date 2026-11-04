# Task Assignment: Survey Explorer 3 (Delivery Activation & Directory Endpoints Specialist)

## Working Directory
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_3`

## Inputs
- Authoritative Request: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md`
- Source Codebase: `c:\Users\MILAN\Downloads\hp_erp`
- DB Guidelines: `c:\Users\MILAN\Downloads\hp_erp\Docs\agentic-crm\DB-RULES.md`

## Mission & Scope
Investigate outbound delivery activation, credential onboarding, and directory endpoint stability:
1. Examine Outbound Delivery Activation (R4):
   - Table `school_setup`: check current value of `outbound_email_enabled` for `sub_institute_id = 15`.
   - Tables `smtp_details` and `sms_api_details`: inspect current records for tenant 15 vs active platform standard configurations (check other active tenants, e.g., platform defaults or tenant 1).
   - Investigate G2G delivery settings backend/schema (referenced at https://g2gv0.vercel.app/settings?s=delivery).
   - Trace how credentials invitation emails are triggered and sent (controllers, mailables, queues, or notification classes).
   - Formulate a safe testing strategy with designated test email addresses / payload interception/mocking to verify credential generation and delivery flow without sending emails to real employee inboxes.
2. Examine Directory Endpoints & Stability (R2 & R6):
   - Locate where `Attempt to read property "jobrole" on null` occurs in legacy Blade views or API controllers.
   - Trace the exact query / relationship logic connecting `tbluser`, `allocated_standards`, and `jobtitle_id` / `s_user_jobrole`.
   - Inspect Next.js `/api/employees-management` endpoints (or corresponding Laravel backend API endpoints) and legacy Blade employee directory views.
   - Determine how to execute HTTP verification requests or local endpoint requests (e.g. via curl, php artisan, or local dev server) to confirm HTTP 200 with populated job role data and zero 500 errors.

## Output Requirements
Produce a comprehensive handoff report at:
`c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_3\handoff.md`
Also update `progress.md` in your working directory.
When done, send a message to the orchestrator with your findings.
