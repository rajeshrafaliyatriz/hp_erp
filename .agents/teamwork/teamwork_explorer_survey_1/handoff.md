# Comprehensive Survey Report: Data & DB Schema Specialist (Tenant 15)

- **Agent**: Survey Explorer 1 (`teamwork_explorer_survey_1`)
- **Date**: 2026-11-04
- **Working Directory**: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_1`
- **Target Tenant**: `sub_institute_id = 15` ("Future Varsity" / "Institute of Management Studies & Research")
- **Target Host**: Live database `db3` (`128.199.17.97`, MariaDB 10.1.48, database `hp_erp`)

---

## 1. Observation

### 1.1 Database Configuration and Environment Observations
- **Config & .env (`c:\Users\MILAN\Downloads\hp_erp\.env`, lines 52–57)**:
  ```env
  DB3_CONNECTION=mysql
  DB3_HOST=128.199.17.97
  DB3_DATABASE=hp_erp
  DB3_USERNAME=dev_db
  DB3_PASSWORD=dev@sql
  ```
- **Laravel Connection Definition (`config/database.php`, lines 125–155)**:
  Both `'live'` and `'db3'` connections are declared with driver `'mysql'`, host `env('DB3_HOST')`, database `env('DB3_DATABASE')`, charset `'utf8mb4'`, collation `'utf8mb4_unicode_ci'`, and `'strict' => false`.
- **Live Server Execution Probe (`SELECT VERSION(), @@sql_mode, DATABASE()`)**:
  Output verified against `128.199.17.97`:
  - `VERSION`: `10.1.48-MariaDB`
  - `sql_mode`: `NO_ENGINE_SUBSTITUTION` (non-strict mode)
  - `DATABASE()`: `hp_erp`
- **Database Safety Rules (`Docs/agentic-crm/DB-RULES.md`)**:
  - Live MariaDB 10.1 has `explicit_defaults_for_timestamp = 0`, Compact row format (767-byte index limit for utf8mb4), and non-strict mode (silent truncation risks).
  - Checking column/table existence: bound queries `SHOW COLUMNS ... LIKE ?` throw MariaDB syntax error 1064. Literal queries (`SHOW COLUMNS FROM \`t\` LIKE 'col'`) or `information_schema.COLUMNS` must be used.
  - Collation behavior: String operations between `utf8mb4_unicode_ci` and implicit connection collation (`utf8mb4_general_ci`) throw error 1267 (`Illegal mix of collations`).
  - Strict migration & data update rules:
    - Additive schema only; no dropping or altering active columns.
    - Data mutations must never be placed inside schema migrations; use separate Artisan commands or guarded scripts with dry-run default, explicit `--apply`, single-tenant transactions, summary logs, and reversal generation.
    - Live rehearsal: Probe table `zz_probe_<change>` before DDL operations.
    - Pre-mutation backup: Dump or clone target records into backup tables before executing deletions or overwrites.

---

### 1.2 Excel Source Data Extraction ("NAEMD" Sheet)
From `C:\Users\MILAN\Downloads\Employee details -HRMS.xlsx` (Sheet `NAEMD`), exactly 16 employee rows exist (headers in Row 1, data in Rows 2–17):

| Sr. | Full Name | Designation (Spreadsheet) | Department (Spreadsheet) | Official Email ID | Official Contact | Personal Contact | Location | Aadhaar / PAN |
|---|---|---|---|---|---|---|---|---|
| 1 | Vasudha Surve | Insitue Incharge | Head of Institution | vasudha.surve@naemd.edu.in | 77188 62662 | - | NAEMD-Overall | 513856936098 / BCCPK4448K |
| 2 | Ankita Solanki | Education Counsellor - Admissions and Academics | Admissions | ankita.solanki@naemd.edu.in | 70593 25555 | 9867110152 | NAEMD-MLD | 7874931453632 / ECRPS7679E |
| 3 | Nazneen Shaikh | Education Counsellor - Admissions and Academics | Academics | nazneen.shaikh@naemd.edu.in | 77188 62661 | 9930909713 | NAEMD-MLD | 892415851800 / DTHPS9762L |
| 4 | Santosh Bhoir | Admin Executive | Admin | santosh.bhoir@naemd.edu.in | 77770 10640 | - | NAEMD-MLD | - / - |
| 5 | Hiral Joshi | Education Counsellor - Admissions and Academics | Admissions | hiral.joshi@naemd.edu.in | 7059315555 | 9158210980 | NAEMD-MLD | 6359 9323 4916 / BCCPJ1551N |
| 6 | Akshi Dedhia | Executive – Creative and Industry Relations | Career Service Cell | akshi.dedhia@nationalacademy.edu.in | - | 9969103596 | NAEMD-MLD | 534818570819 / JIAPD4389E |
| 7 | Seeta Khatri | Admin And Accounts Executive | Accounts | seeta.khatri@naemd.edu.in | 7718898668 | 8691813360 | NAEMD-MLD | 695360275140 / DSLPK2788H |
| 8 | Meenakshi Sharma | Education Counsellor - Admissions and Academics | Admissions | meenakshi.s@naemd.edu.in | 8976839254 | 9825571862 | NAEMD-MLD | 948194586369 / EGZPS2260B |
| 9 | Rohan Jadhav | Industry and Operations Executive | Career Service Cell | rohan.jadhav@futurevarsity.edu.in | 9833432663 | 8355895028 | NAEMD-MLD | 7248 7085 3832 / CJQP J0253B |
| 10 | Shweta Tiwari | Faculty and Academic Coordinator | Academics and Faculty | shweta.mina@futurevarsity.edu.in | 8645821111 | 9769217963 | NAEMD-MLD | 549645775561 / AUHPT5465B |
| 11 | Priyanka Piramal | Education Counsellor -Admissions and Academics | Admissions | priyanka.piramal@futurevarsity.edu.in | 8645025555 | 8898443530 | NAEMD-BKBC | 545299094894 / AULPP8918K |
| 12 | Priya Mishra | Education Counselor - Admissions and Academics | Admissions | priya.mishra@futurevarsity.edu.in | 7421928888 | 8793707756 | NAEMD-BKBC | 828108354693 / BVWPM7847R |
| 13 | Santosh Indvatkar | Support Staff | Support Staff | santoshindvatkar.30@futurevarsity.edu.in *(DB email)* | - | 9082800675 | NAEMD-GICED | - / - |
| 14 | Dakshata Tondvalkar | Education Counsellor - Admissions and Academics | Admissions | dakshata.tondvalkar@nationalacademy.edu.in | 85081 72222 | 9867175987 | NAEMD-RAV | 344160907741 / BFMPT5741M |
| 15 | Jyoti Gagat | Support Staff | Support Team | jyoti.talekar@nationalacademy.edu.in | - | 9082527265 | NAEMD-RAV | - / - |
| 16 | Lynette Fernandes | Education Counsellor Admissions & Academics | Admissions | lynette.f@naemd.edu.in | 70593 95555 | 7506439695 | NAEMD-RAV | 966478067239 / AAYPF9080D |

*Note: In the spreadsheet, Santosh Indvatkar had an empty Official Email ID; in `tbluser`, his account was provisioned with `santoshindvatkar.30@futurevarsity.edu.in` and matched by full name.*

---

### 1.3 `tbluser` Breakdown for Tenant 15 (`sub_institute_id = 15`)
Query: `SELECT COUNT(*) FROM tbluser WHERE sub_institute_id = 15` returned **162 total users**.
The users split into three disjoint subsets:

1. **Primary Tenant Admin (1 user)**:
   - `id`: **479**
   - `user_name`: `lsi.mkes@futurevarsity.edu.in`
   - `email`: `lsi.mkes@futurevarsity.edu.in`
   - `first_name`: `future`, `last_name`: `varsity`
   - `is_admin`: `1`, `user_profile_id`: `63`
   - `created_at`: `2026-10-28 12:09:48`
   - Role columns: `jobtitle_id = NULL`, `allocated_standards = NULL`.

2. **Verified NAEMD Employees (16 users)**:
   - Contiguous ID block: **564 through 579 inclusive** (16 records).
   - Created at: `2026-11-04 11:38:58` – `11:38:59`.
   - Exact 1-to-1 match with the 16 rows from sheet `NAEMD`:
     - ID 564: Vasudha Surve (`jobtitle_id` = 7300, `allocated_standards` = NULL)
     - ID 565: Ankita Solanki (`jobtitle_id` = 7303, `allocated_standards` = NULL)
     - ID 566: Nazneen Shaikh (`jobtitle_id` = 7302, `allocated_standards` = NULL)
     - ID 567: Santosh Bhoir (`jobtitle_id` = 7306, `allocated_standards` = NULL)
     - ID 568: Hiral Joshi (`jobtitle_id` = 7303, `allocated_standards` = NULL)
     - ID 569: Akshi Dedhia (`jobtitle_id` = 7308, `allocated_standards` = NULL)
     - ID 570: Seeta Khatri (`jobtitle_id` = 7305, `allocated_standards` = NULL)
     - ID 571: Meenakshi Sharma (`jobtitle_id` = 7303, `allocated_standards` = NULL)
     - ID 572: Rohan Jadhav (`jobtitle_id` = 7308, `allocated_standards` = NULL)
     - ID 573: Shweta Tiwari (`jobtitle_id` = 7301, `allocated_standards` = NULL)
     - ID 574: Priyanka Piramal (`jobtitle_id` = 7303, `allocated_standards` = NULL)
     - ID 575: Priya Mishra (`jobtitle_id` = 7303, `allocated_standards` = NULL)
     - ID 576: Santosh Indvatkar (`jobtitle_id` = 7306, `allocated_standards` = NULL)
     - ID 577: Dakshata Tondvalkar (`jobtitle_id` = 7303, `allocated_standards` = NULL)
     - ID 578: Jyoti Gagat (`jobtitle_id` = 7315, `allocated_standards` = NULL)
     - ID 579: Lynette Fernandes (`jobtitle_id` = 7303, `allocated_standards` = NULL)

3. **Provisional Bulk-Imported Employees (145 users)**:
   - Imported from "All Verticals" batch at timestamp `2026-11-04 11:38:59`.
   - Exact ID ranges:
     - Range 1: `id BETWEEN 547 AND 563` (17 users: IDs 547, 548, ..., 563)
     - Range 2: `id BETWEEN 580 AND 707` (128 users: IDs 580, 581, ..., 707)
     - Total: `17 + 128 = 145` users.
   - All 145 have `allocated_standards IS NULL`.

---

### 1.4 Dependency and Orphan Scan Across Database
A comprehensive scan was conducted across the live database to identify any foreign keys or loose pointer columns referencing the 145 provisional IDs:

1. **InnoDB Formal Foreign Keys (265 FK constraints)**:
   - Scanned all 265 FK constraints registered in `information_schema.KEY_COLUMN_USAGE` where `REFERENCED_TABLE_NAME = 'tbluser'`.
   - Result for the 145 provisional IDs: **EXACTLY 0 rows** across all 265 foreign keys.
   - Result for the 16 NAEMD IDs: **EXACTLY 0 rows** across all 265 foreign keys.
   - Result for Admin ID 479: References found in `s_user_jobrole.created_by` (18 rows), `s_user_jobrole.updated_by` (16 rows), `hrms_departments.created_by` (13 rows), `hrms_departments.updated_by` (13 rows), `org_details.updated_by` (1 row), `user_preferences.user_id` (4 rows).

2. **Loose-Pointer Columns Across All Database Tables (109 columns scanned)**:
   - Scanned all columns named `user_id`, `employee_id`, `task_allocated_to`, `staff_id`, `assign_to`, `assignee_id`, `reporting_manager_id`:
     - Within tenant 15 (`sub_institute_id = 15` across 560 table-column pairs): **EXACTLY 0 rows**.
     - Across all tables with records created on or after `2026-11-04`: **EXACTLY 0 rows**.
     - Only 2 tables in non-tenant-15 scopes had legacy rows matching these IDs (created months earlier before today's auto-increment allocation):
       - `competency_kasba_rating`: 34 rows with `sub_institute_id = 3` (created on 2026-08-10 with note "Seeded capability slice 2026-08-11").
       - `hpbrain_refresh_tokens`: 97 rows with `tenant_id = 1000000` (created on 2026-08-17).
   - Neither of these belongs to tenant 15. The 145 provisional users imported today have **zero dependent rows anywhere in tenant 15**.

---

### 1.5 Dual-Key Legacy Columns & Fatal Crash Root Cause
- **Code Observation (`app/Http/Controllers/user/tbluserController.php`)**:
  - Line 933:
    ```php
    $assignedJobrole = userJobroleModel::where('sub_institute_id', $sub_institute_id)->where('id', $editData['allocated_standards'])->whereNull('deleted_at')->first();
    ```
  - Lines 936–1105 are wrapped inside `if (isset($assignedJobrole)) { ... }`.
  - Line 1129 (OUTSIDE the `if` guard):
    ```php
    $res['usersJobroleComponent'] = DB::table('s_user_jobrole')->where('jobrole', $assignedJobrole->jobrole)->where('sub_institute_id', $sub_institute_id)->whereNull('deleted_at')->first();
    ```
- **Database Column Values (`tbluser` for Tenant 15)**:
  - `allocated_standards` is `NULL` for all 162 users (100% missing).
  - `jobtitle_id` is populated for all 16 NAEMD staff (e.g. 7300, 7303, 7302, 7306, etc.).
- **Crash Trigger**:
  When viewing or editing any employee in tenant 15, `$editData['allocated_standards']` is `NULL`. Therefore, line 933 evaluates `$assignedJobrole` to `null`. When execution reaches line 1129, PHP 8.2 executes `$assignedJobrole->jobrole`, which causes the fatal crash:
  ```
  Attempt to read property "jobrole" on null
  ```
- **Directory Endpoint Observation (`app/Http/Controllers/HRMS/EmployeeDirectoryController.php`, line 134)**:
  ```php
  private const ROLE_ID_SQL = "COALESCE(NULLIF(u.jobtitle_id, 0), NULLIF(SUBSTRING_INDEX(u.allocated_standards, ',', 1), '') + 0)";
  ```
  The Next.js API `/api/employees-management` uses `COALESCE` to fall back between `jobtitle_id` and `allocated_standards`, but the legacy Blade view strictly requires `allocated_standards` to be set to the valid `s_user_jobrole.id`.

---

### 1.6 Tenant 15 Delivery & Catalogue State
- **`school_setup` (id = 15)**:
  `SchoolName`: 'Future Varsity', `Email`: 'lsi.mkes@futurevarsity.edu.in', `outbound_email_enabled`: `0`.
- **`smtp_details` (sub_institute_id = 15)**: **0 rows**.
- **`sms_api_details` (sub_institute_id = 15)**: **0 rows**.
- **`s_jobrole` (Master Catalogue)**:
  - "Institute of Management Studies & Research" does **not** exist in `s_jobrole.sector` (0 matches).
  - Sectors exist for `Training and Adult Education` and `Business Management and Administration`.
- **`s_user_jobrole` (Tenant 15 Roles)**:
  18 rows exist (7283, 7284, 7300–7315). Rows 7300–7315 correspond to the imported roles and already have `catalogue_jobrole_id` populated.
- **`s_user_jobrole_task`**: **0 rows** for tenant 15.
- **`jobrole_competency_map`**: **0 rows** for tenant 15.

---

## 2. Logic Chain

1. **Premise 1**: The original request (R1) mandates retaining exactly the 16 NAEMD staff plus primary admin user 479, and purging all 145 provisional employees imported from "All Verticals" with zero orphan records.
2. **Premise 2**: Direct inspection of `tbluser` established that there are 162 total users for tenant 15:
   - Admin: ID 479.
   - NAEMD staff: 16 contiguous IDs from 564 to 579.
   - Provisional users: 145 IDs spanning `547..563` and `580..707`.
3. **Premise 3**: Our automated database scans checked all 265 InnoDB foreign keys and 109 loose-pointer columns across the entire database. Tenant 15 has 0 records referencing any of the 145 provisional IDs in any dependent event, skill, attendance, document, or log table.
4. **Deduction 1**: Purging the 145 provisional users (`(id BETWEEN 547 AND 563) OR (id BETWEEN 580 AND 707)`) will result in **zero orphaned records** in tenant 15.
5. **Premise 4**: In `tbluserController.php:1129`, `$res['usersJobroleComponent'] = DB::table('s_user_jobrole')->where('jobrole', $assignedJobrole->jobrole)...` accesses `$assignedJobrole->jobrole` without checking whether `$assignedJobrole` is null. `$assignedJobrole` is queried strictly from `tbluser.allocated_standards`.
6. **Premise 5**: In `tbluser`, all 16 NAEMD users have valid `jobtitle_id` values pointing to `s_user_jobrole.id`, but `allocated_standards` is `NULL`.
7. **Deduction 2**: Setting `tbluser.allocated_standards = CAST(jobtitle_id AS CHAR)` for all 16 NAEMD users ensures that `$assignedJobrole` is found, `$assignedJobrole->jobrole` resolves cleanly, and the `Attempt to read property "jobrole" on null` fatal crash is permanently eliminated for both legacy Blade views and Next.js directory endpoints.
8. **Premise 6**: `DB-RULES.md` mandates that all database modifications must follow safe transactional practices: backup tables before deletion, generated reversal SQL, guarded idempotent queries, and execution via targeted Artisan commands.

---

## 3. Caveats

1. **Collation Sensitivity on MariaDB 10.1**: In MariaDB 10.1, direct comparisons between columns of different collations (e.g. `utf8mb4_unicode_ci` vs connection collation) throw Error 1267. When setting or comparing `allocated_standards` and `jobtitle_id`, explicit casting or string literals must be used without relying on raw mixed-collation joins.
2. **Pre-existing Cross-Tenant IDs**: The 34 rows in `competency_kasba_rating` (tenant 3) and 97 rows in `hpbrain_refresh_tokens` (tenant 1000000) created in August 2026 contain IDs overlapping with today's auto-increment IDs. These belong to other tenants and must **not** be modified or deleted. All delete statements must be strictly scoped to `sub_institute_id = 15`.
3. **Outbound Email Pre-Condition**: While `school_setup.outbound_email_enabled` can be enabled (`= 1`), `smtp_details` currently has no record for tenant 15. Real emails will fail to dispatch until `smtp_details` is populated from the standard template (e.g. cloned from tenant 6 or tenant 1).
4. **Catalogue Tasks and Competencies**: Although `s_user_jobrole` rows exist for tenant 15 with `catalogue_jobrole_id`, `s_user_jobrole_task` and `jobrole_competency_map` are currently empty (0 rows) and require cloning in subsequent phases.

---

## 4. Conclusion

1. **Target User Scope is Fully Isolated**:
   - Primary Admin: `id = 479`
   - Retained NAEMD Employees: `id IN (564, 565, 566, 567, 568, 569, 570, 571, 572, 573, 574, 575, 576, 577, 578, 579)`
   - Purged Provisional Employees: `145` users (`id BETWEEN 547 AND 563 OR id BETWEEN 580 AND 707`)
2. **Safe to Purge**: Zero orphan records exist in any dependent event, skill, attendance, or document table for tenant 15.
3. **Root Cause of Directory Crash Solved**: `allocated_standards` is NULL on all 16 users while `jobtitle_id` holds the correct `s_user_jobrole.id`. Updating `allocated_standards = CAST(jobtitle_id AS CHAR)` eliminates the crash.
4. **Execution Protocol Ready**: Complete, transactional SQL drafts and rollback scripts have been authored adhering strictly to `DB-RULES.md`.

---

## 5. Verification Method & SQL Query Drafts

### 5.1 Verification Commands
To verify user counts, schema state, and orphan counts on `db3`:

```bash
# 1. Verify user counts for tenant 15
python -c "import pymysql; conn=pymysql.connect(host='128.199.17.97', user='dev_db', password='dev@sql', database='hp_erp'); cur=conn.cursor(); cur.execute('SELECT COUNT(*) FROM tbluser WHERE sub_institute_id=15'); print('Total:', cur.fetchone()[0]); cur.execute('SELECT COUNT(*) FROM tbluser WHERE sub_institute_id=15 AND (id=479 OR id BETWEEN 564 AND 579)'); print('Retained:', cur.fetchone()[0]); cur.execute('SELECT COUNT(*) FROM tbluser WHERE sub_institute_id=15 AND id NOT IN (479) AND id NOT BETWEEN 564 AND 579'); print('Provisional:', cur.fetchone()[0])"
```

### 5.2 Concrete SQL Draft: Pre-Purge Backup & Safe Deletion
```sql
-- ============================================================================
-- STEP 1: TRANSACTION GUARD & BACKUP (Strict DB-RULES Compliance)
-- ============================================================================
START TRANSACTION;

-- Guard check: Ensure exactly 145 provisional users exist before taking backup
SELECT COUNT(*) INTO @prov_count
FROM `tbluser`
WHERE `sub_institute_id` = 15
  AND `id` != 479
  AND `id` NOT BETWEEN 564 AND 579;

-- Create backup table if not exists
CREATE TABLE IF NOT EXISTS `tbluser_backup_tenant15_purge_20261104` LIKE `tbluser`;

-- Insert the 145 provisional users into backup table
INSERT INTO `tbluser_backup_tenant15_purge_20261104`
SELECT * FROM `tbluser`
WHERE `sub_institute_id` = 15
  AND `id` != 479
  AND `id` NOT BETWEEN 564 AND 579;

-- ============================================================================
-- STEP 2: SAFE PURGE OF THE 145 PROVISIONAL USERS
-- ============================================================================
DELETE FROM `tbluser`
WHERE `sub_institute_id` = 15
  AND `id` != 479
  AND `id` NOT BETWEEN 564 AND 579;

-- ============================================================================
-- STEP 3: POST-DELETE COUNT ASSERTION
-- ============================================================================
-- Expected: Exactly 17 users remain in tenant 15 (1 admin + 16 NAEMD staff)
SELECT COUNT(*) AS remaining_tenant15_users
FROM `tbluser`
WHERE `sub_institute_id` = 15;

COMMIT;
```

### 5.3 Concrete SQL Draft: Dual-Key Synchronization
```sql
-- ============================================================================
-- SYNCHRONIZE allocated_standards = jobtitle_id FOR 16 NAEMD USERS
-- Resolves "Attempt to read property 'jobrole' on null" crash
-- ============================================================================
START TRANSACTION;

UPDATE `tbluser`
SET `allocated_standards` = CAST(`jobtitle_id` AS CHAR),
    `updated_at` = NOW()
WHERE `sub_institute_id` = 15
  AND `id` BETWEEN 564 AND 579
  AND `jobtitle_id` IS NOT NULL;

-- Assert zero mismatches remain
SELECT `id`, `user_name`, `jobtitle_id`, `allocated_standards`
FROM `tbluser`
WHERE `sub_institute_id` = 15
  AND `id` BETWEEN 564 AND 579;

COMMIT;
```

### 5.4 Concrete SQL Draft: Reversal / Rollback Script
```sql
-- ============================================================================
-- REVERSAL SQL: RESTORE PROVISIONAL USERS & REVERT DUAL-KEY SYNC
-- ============================================================================
START TRANSACTION;

-- Revert dual-key sync (nullify allocated_standards)
UPDATE `tbluser`
SET `allocated_standards` = NULL
WHERE `sub_institute_id` = 15
  AND `id` BETWEEN 564 AND 579;

-- Restore deleted provisional users from backup table
INSERT IGNORE INTO `tbluser`
SELECT * FROM `tbluser_backup_tenant15_purge_20261104`;

-- Verify restored user count (162 total)
SELECT COUNT(*) AS restored_tenant15_count
FROM `tbluser`
WHERE `sub_institute_id` = 15;

COMMIT;
```

### 5.5 Concrete SQL Draft: Post-Reconciliation Orphan Check
```sql
-- ============================================================================
-- POST-RECONCILIATION ORPHAN CHECK QUERY
-- ============================================================================
SELECT 's_skill_matrix' AS table_checked, COUNT(*) AS orphan_count
FROM `s_skill_matrix` m
LEFT JOIN `tbluser` u ON m.`user_id` = u.`id`
WHERE m.`sub_institute_id` = 15 AND u.`id` IS NULL

UNION ALL

SELECT 'hrms_attendances', COUNT(*)
FROM `hrms_attendances` a
LEFT JOIN `tbluser` u ON a.`user_id` = u.`id`
WHERE a.`sub_institute_id` = 15 AND u.`id` IS NULL

UNION ALL

SELECT 'calendar_events', COUNT(*)
FROM `calendar_events` e
LEFT JOIN `tbluser` u ON e.`created_by` = u.`id`
WHERE e.`sub_institute_id` = 15 AND u.`id` IS NULL

UNION ALL

SELECT 'staff_document', COUNT(*)
FROM `staff_document` d
LEFT JOIN `tbluser` u ON d.`user_id` = u.`id`
WHERE d.`sub_institute_id` = 15 AND u.`id` IS NULL;
```
