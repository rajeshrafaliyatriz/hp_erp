# Project: Future Varsity Tenant Reconciliation and Delivery Activation (sub_institute_id = 15)

## Architecture
- **Target Host & Database**: MariaDB 10.1.48 on `128.199.17.97` (`db3` / `live`), database `hp_erp`.
- **Tenant Scope**: `sub_institute_id = 15` ("Future Varsity" / "Institute of Management Studies & Research").
- **Primary Admin**: User ID 479 (`lsi.mkes@futurevarsity.edu.in`).
- **Core Entities & Data Flow**:
  - `tbluser`: Primary user registry. Target state: exactly 17 users (Admin 479 + 16 NAEMD staff IDs 564..579). Provisional 145 users backed up and purged. Dual keys synchronized (`allocated_standards = CAST(jobtitle_id AS CHAR)`).
  - `s_jobrole`: Master catalogue (3,347 roles). Sector mapping to `Training and Adult Education` (academic) and `Business Management and Administration` (admin/support).
  - `s_user_jobrole`: Tenant adopted roles. Adopts 12 distinct NAEMD designations with explicit `catalogue_jobrole_id` provenance. Obsolete department placeholder roles (7300..7315) removed.
  - `s_user_jobrole_task`: Cloned tasks from `s_jobrole_task` linked via `jobrole_id` and `catalogue_task_id`.
  - `competency` & `competency_kasba_item` & `jobrole_competency_map`: Institutional competencies and KASBA items mapped to adopted job roles.
  - `tbluserprofilemaster` & `tblgroupwise_rights_g2g`: RBAC profile assignment (`Admin` 63, `Department Head` 60, `Executive` 64, `Employee` 58).
  - `school_setup` & `smtp_details` & `sms_api_details`: Delivery activation (`outbound_email_enabled = 1`, active platform credentials cloned).
  - `app/Support/MailGate.php` & `app/Services/Auth/InviteService.php`: Outbound mail gating and invitation flow.
  - `app/Http/Controllers/user/tbluserController.php` & Next.js `/api/employees-management`: Directory stability and profile retrieval with zero 500 errors.

## Feature Inventory
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| F1 | Master Catalogue Sector Mapping | Map NAEMD designations to standard sectors (`Training and Adult Education`, `Business Management and Administration`) in `s_jobrole` | M1 | ORIGINAL_REQUEST §R3 |
| F2 | Catalogue Adoption (12 Designations) | Adopt 12 distinct NAEMD designations into `s_user_jobrole` with valid `catalogue_jobrole_id` provenance | M1 | ORIGINAL_REQUEST §R3 |
| F3 | Task Cloning | Clone catalogue tasks from `s_jobrole_task` into `s_user_jobrole_task` linked via `jobrole_id` and `catalogue_task_id` | M1 | ORIGINAL_REQUEST §R3 |
| F4 | KASBA Competency Mapping | Seed institutional competencies, KASBA items, and map to adopted roles in `jobrole_competency_map` | M1 | ORIGINAL_REQUEST §R3 |
| F5 | Pre-Purge Backup Table | Create `tbluser_backup_tenant15_purge_20261104` and back up the 145 provisional users | M2 | DB-RULES.md & ORIGINAL_REQUEST §R1 |
| F6 | Employee Scope Reduction | Purge 145 provisional users from `tbluser`, retaining exactly 17 users (Admin 479 + 16 NAEMD staff 564..579) | M2 | ORIGINAL_REQUEST §R1 |
| F7 | Zero Orphan Record Verification | Assert 0 orphan records in all 265 FK constraints and dependent event/skill/attendance tables | M2 | ORIGINAL_REQUEST §R1 |
| F8 | Transactional Reversal SQL Generation | Author and validate rollback SQL scripts for all mutations | M2 | DB-RULES.md & ORIGINAL_REQUEST §R1 |
| F9 | Dual-Key Schema Synchronization | Synchronize `tbluser.allocated_standards = CAST(jobtitle_id AS CHAR)` for all 16 retained employees | M3 | ORIGINAL_REQUEST §R2 |
| F10 | Elimination of "jobrole on null" Fatal Crash | Resolve crash in `tbluserController.php` lines 1129/1581 and Blade views | M3 | ORIGINAL_REQUEST §R2 |
| F11 | Role and Permission Administration | Align `tbluser.user_profile_id` with spreadsheet designations (`Admin`, `Department Head`, `Executive`, `Employee`) | M3 | ORIGINAL_REQUEST §R5 |
| F12 | Clean Up Deprecated Role Placeholders | Delete old provisional department-named roles (IDs 7300..7315) from `s_user_jobrole` | M3 | Survey Findings |
| F13 | Outbound Email Delivery Activation | Set `school_setup.outbound_email_enabled = 1` for `sub_institute_id = 15` | M4 | ORIGINAL_REQUEST §R4 |
| F14 | SMTP & SMS Profile Configuration | Clone active platform configuration into `smtp_details` and `sms_api_details` for tenant 15 | M4 | ORIGINAL_REQUEST §R4 |
| F15 | Credential Invitation Flow Testing | Verify invitation generation and delivery flow with designated test email address without emailing real inboxes | M4 | ORIGINAL_REQUEST §R4 |
| F16 | Automated SQL Proof Suite Execution | Run full proof queries demonstrating all 6 requirements and acceptance criteria | M5 | ORIGINAL_REQUEST §R6 |
| F17 | HTTP Directory & Profile Endpoint Verification | Verify HTTP 200 and zero 500 errors across legacy Blade views and Next.js `/api/employees-management` | M5 | ORIGINAL_REQUEST §R2 & §R6 |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Catalogue Adoption & Competency Population | Features F1, F2, F3, F4: Adopt 12 designations into `s_user_jobrole`, clone tasks into `s_user_jobrole_task`, seed KASBA competencies in `jobrole_competency_map` | None | PLANNED |
| M2 | Employee Scope Reduction & Orphan Purge | Features F5, F6, F7, F8: Backup and purge 145 provisional employees, verify 0 orphans across all FKs, generate reversal SQL | None | PLANNED |
| M3 | Dual-Key Sync & Role Administration | Features F9, F10, F11, F12: Sync `allocated_standards` = `jobtitle_id` = adopted `s_user_jobrole.id`, set `user_profile_id`, delete placeholder roles 7300..7315 | M1, M2 | PLANNED |
| M4 | Outbound Delivery Activation & Credential Onboarding | Features F13, F14, F15: Enable `school_setup`, configure `smtp_details`/`sms_api_details`, verify invitation generation and delivery without live emailing | None | PLANNED |
| M5 | E2E Automated Verification & Proof Output | Features F16, F17: Run proof queries for all acceptance criteria, verify HTTP 200 and zero 500 errors on directory & profile endpoints | M1, M2, M3, M4 | PLANNED |

## Interface Contracts
### Database Connection Contract
- Connection: `live` or `db3` (`128.199.17.97`, MariaDB 10.1.48, db `hp_erp`).
- Transaction Mode: Explicit `START TRANSACTION` ... `COMMIT` / `ROLLBACK`.
- Collation: Avoid cross-collation string joins; use `CAST(column AS CHAR)` or clean equality.
- Idempotency: All scripts must check for existing state or use guarded conditions.

### User & Job Role Contract
- `tbluser.id` for retained staff: 564..579 (16 users) + 479 (Admin).
- `tbluser.jobtitle_id` == `tbluser.allocated_standards` == `s_user_jobrole.id`.
- `s_user_jobrole.catalogue_jobrole_id` points to master `s_jobrole.id`.
- `s_user_jobrole_task.catalogue_task_id` points to `s_jobrole_task.id`.
- `jobrole_competency_map.jobrole_id` points to `s_user_jobrole.id`.

### Delivery Contract
- `school_setup.outbound_email_enabled` = 1 where `id = 15`.
- `smtp_details.sub_institute_id` = 15.
- `sms_api_details.sub_institute_id` = 15.
- Test delivery address: explicit override in `InviteService::issue(..., deliverTo: 'test@example.com')` or payload capture.

## Code Layout
- Agent metadata: `.agents/teamwork/`
- SQL migration scripts & reversal scripts: `database/migrations/reconciliation_tenant15/` or `Docs/agentic-crm/reconciliation_tenant15/`
- Verification scripts: `tests/Reconciliation/` or standalone test scripts
- Application controllers:
  - `app/Http/Controllers/user/tbluserController.php`
  - `app/Http/Controllers/HRMS/EmployeeDirectoryController.php`
  - `app/Support/MailGate.php`
  - `app/Services/Auth/InviteService.php`
