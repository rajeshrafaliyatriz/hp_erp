# E2E Test Infra: Tenant 15 Reconciliation & Delivery Activation

## Test Philosophy
- Opaque-box, requirement-driven verification against live database `db3` (`128.199.17.97`, database `hp_erp`, MariaDB 10.1).
- Pass/fail semantics: Binary assertions on row counts, orphan counts, foreign key integrity, column parity, HTTP response status codes (HTTP 200), and delivery activation flags.

## Feature Inventory Test Mapping
| # | Feature | Requirement | Verification Channel | Expected Outcome |
|---|---------|-------------|----------------------|------------------|
| F1 | Master Catalogue Sector Mapping | R3 | SQL on `s_jobrole` | "Institute of Management Studies & Research" = 0, standard sectors exist |
| F2 | Catalogue Adoption (12 Designations) | R3 | SQL on `s_user_jobrole` | 12 distinct designations exist for tenant 15 with valid `catalogue_jobrole_id` |
| F3 | Task Cloning | R3 | SQL on `s_user_jobrole_task` | Tasks populated for all 12 roles with `catalogue_task_id` provenance |
| F4 | KASBA Competency Mapping | R3 | SQL on `jobrole_competency_map` | Competencies and KASBA items mapped to all adopted roles |
| F5 | Pre-Purge Backup Table | R1 | SQL on `tbluser_backup_tenant15_purge_20261104` | 145 rows preserved before purge |
| F6 | Employee Scope Reduction | R1 | SQL on `tbluser` | Exactly 17 users exist for tenant 15 (Admin 479 + 16 NAEMD staff 564..579) |
| F7 | Zero Orphan Records | R1 | SQL across all 265 FKs and dependent tables | Orphan count = 0 |
| F8 | Transactional Reversal SQL | R1 | SQL rehearsal / reversal script | Reversal script validated and executable |
| F9 | Dual-Key Schema Sync | R2 | SQL on `tbluser` | Zero rows where `jobtitle_id != allocated_standards` for tenant 15 |
| F10 | Fatal Crash Elimination | R2 | Code & Unit/Endpoint run | No `Attempt to read property "jobrole" on null` on edit/show screens |
| F11 | Role and Permission Administration | R5 | SQL on `tbluser.user_profile_id` | Profiles match NAEMD designations (`Admin` 63, `Dept Head` 60, `Executive` 64, `Employee` 58) |
| F12 | Clean Up Deprecated Roles | R3 | SQL on `s_user_jobrole` | Zero rows with IDs 7300..7315 for tenant 15 |
| F13 | Outbound Delivery Enabled | R4 | SQL on `school_setup` | `outbound_email_enabled = 1` for `id = 15` |
| F14 | SMTP / SMS Configured | R4 | SQL on `smtp_details`, `sms_api_details` | Rows configured for `sub_institute_id = 15` |
| F15 | Credential Invitation Flow | R4 | Application Service / Tinker | Invitation generation verified with test email address, 0 emails to real inboxes |
| F16 | Automated SQL Proof Suite | R6 | CLI Python / Artisan runner | 100% pass across all assertions |
| F17 | HTTP Directory & Profile Endpoints | R2 & R6 | HTTP / Laravel Request runner | HTTP 200 and zero 500 errors across Blade and Next.js endpoints |

## Test Runner
- Script: `run_tenant15_proofs.py` or `php artisan test:tenant15-reconciliation`
- Expected: All assertions exit code 0.
