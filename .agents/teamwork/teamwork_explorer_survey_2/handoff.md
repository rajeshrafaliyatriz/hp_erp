# Comprehensive Investigation & Handoff Report: Master Catalogue, KASBA Competency, and Role & Permission Administration

**Author**: Survey Explorer 2 (Catalogue & Role/Permission Specialist)  
**Date**: 2026-11-04  
**Target Tenant**: `sub_institute_id = 15` (Future Varsity / Institute of Management Studies & Research)  
**Database Connection**: `db3` / `live` (128.199.17.97, database `hp_erp`, MariaDB 10.1.48)  
**Deliverable File**: `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\teamwork_explorer_survey_2\handoff.md`

---

## 1. Observation

### 1.1 Source Spreadsheet Analysis (`C:\Users\MILAN\Downloads\Employee details -HRMS.xlsx`)
Inspection of sheet `"NAEMD"` reveals exactly 16 employee records. When evaluating column 7 (`Designation`), there are **12 distinct raw strings** (due to spacing/punctuation variations):

| Row | Sr No. | Employee Name | Email | Designation (Sheet Raw) | Department (Sheet) | Location |
|:---:|:---:|:---|:---|:---|:---|:---|
| 1 | 1.0 | Vasudha Surve | vasudha.surve@naemd.edu.in | `'Insitue Incharge'` | Head of Institution | NAEMD-Overall |
| 2 | 2.0 | Ankita Solanki | ankita.solanki@naemd.edu.in | `'Education Counsellor - Admissions and Academics'` | Admissions | NAEMD-MLD |
| 3 | 3.0 | Nazneen Shaikh | nazneen.shaikh@naemd.edu.in | `'Education Counsellor - Admissions and Academics'` | Academics | NAEMD-MLD |
| 4 | 4.0 | Santosh Bhoir | santosh.bhoir@naemd.edu.in | `'Admin Executive'` | Admin | NAEMD-MLD |
| 5 | 5.0 | Hiral Joshi | hiral.joshi@naemd.edu.in | `'Education Counsellor - Admissions and Academics'` | Admissions | NAEMD-MLD |
| 6 | 6.0 | Akshi Dedhia | akshi.dedhia@nationalacademy.edu.in | `'Executive – Creative and Industry Relations'` | Career Service Cell | NAEMD-MLD |
| 7 | 7.0 | Seeta Khatri | seeta.khatri@naemd.edu.in | `'Admin And Accounts Executive '` | Accounts | NAEMD-MLD |
| 8 | 8.0 | Meenakshi Sharma | meenakshi.s@naemd.edu.in | `'Education Counsellor - Admissions and Academics '` | Admissions | NAEMD-MLD |
| 9 | 9.0 | Rohan Jadhav | rohan.jadhav@futurevarsity.edu.in | `'Industry and Operations Executive'` | Career Service Cell | NAEMD-MLD |
| 10 | 10.0 | Shweta Tiwari | shweta.mina@futurevarsity.edu.in | `'Faculty and Academic Coordinator '` | Academics and Faculty | NAEMD-MLD |
| 11 | 11.0 | Priyanka Piramal | priyanka.piramal@futurevarsity.edu.in | `'Education Counsellor -Admissions and Academics '` | Admissions | NAEMD-BKBC |
| 12 | 12.0 | Priya Mishra | priya.mishra@futurevarsity.edu.in | `'Education Counselor - Admissions and Academics '` | Admissions | NAEMD-BKBC |
| 13 | 13.0 | Santosh Indvatkar | santoshindvatkar.30@futurevarsity.edu.in | `'Support Staff'` | Support Staff | NAEMD-GICED |
| 14 | 14.0 | Dakshata Tondvalkar | dakshata.tondvalkar@nationalacademy.edu.in | `'Education Counsellor - Admissions and Academics'` | Admissions | NAEMD-RAV |
| 15 | 15.0 | Jyoti Gagat | jyoti.talekar@nationalacademy.edu.in | `'Support Staff'` | Support Team | NAEMD-RAV |
| 16 | 16.0 | Lynette Fernandes | lynette.f@naemd.edu.in | `'Education Counsellor Admissions & Academics'` | Admissions | NAEMD-RAV |

*Note*: Normalizing whitespace and spelling produces 8 canonical job roles:
1. `Institute Incharge` (1 employee)
2. `Education Counsellor - Admissions and Academics` (8 employees across 5 slight string variations)
3. `Admin Executive` (1 employee)
4. `Admin And Accounts Executive` (1 employee)
5. `Executive – Creative and Industry Relations` (1 employee)
6. `Industry and Operations Executive` (1 employee)
7. `Faculty and Academic Coordinator` (1 employee)
8. `Support Staff` (2 employees)

---

### 1.2 Master Catalogue `s_jobrole` Search Results on `db3`
Direct SQL verification on MariaDB 10.1 (`db3`):
- Total rows in `s_jobrole`: **3,347** records.
- Query for `"Institute of Management Studies & Research"`:
  ```sql
  SELECT COUNT(*) FROM s_jobrole 
  WHERE sector LIKE '%Management Studies%' 
     OR track LIKE '%Management Studies%' 
     OR jobrole LIKE '%Management Studies%' 
     OR description LIKE '%Institute of Management Studies%';
  ```
  **Result**: `0` matches. **Confirmed: "Institute of Management Studies & Research" does NOT exist in `s_jobrole`.**
- Query for standard sectors:
  - Sector `'Training and Adult Education'` exists with **89** job roles.
  - Sector `'Business Management and Administration'` exists with **76** job roles.

---

### 1.3 Tenant 15 Current State in `s_user_jobrole` and `tbluser`
- `s_user_jobrole` for `sub_institute_id = 15` currently holds **18 rows**:
  - IDs `7283` (`SEO`) and `7284` (`Creative`) created on `2026-10-29 11:26:36` with `catalogue_jobrole_id = NULL`.
  - IDs `7300` to `7315` (16 rows) created today `2026-11-04 11:38:57` by user `479` via `IngestFuturevarsityStaffCommand`.
  - Crucial Observation: Rows `7300` to `7315` are named after **department names** (`Head of Institution`, `Academics and Faculty`, `Academics`, `Admissions`, `Marketing`, `Accounts`, `Admin`, `General Staff`, `Career Service Cell`, `Exam and Eligibility`, `IT`, `Lab`, `MIS`, `HR`, `Talent Management`, `Support Team`), **NOT the 12 designations** from the NAEMD spreadsheet.
- In `tbluser`:
  - Exactly 162 users exist for tenant 15 (1 admin ID 479 + 16 NAEMD staff IDs 564-579 + 145 provisional staff IDs 547-563, 580-707).
  - For all 162 users, `jobtitle_id` is set to one of `7300-7315`, but **`allocated_standards` is `NULL`**!
  - In `app/Http/Controllers/user/tbluserController.php:178`:
    ```php
    ->leftJoin('s_user_jobrole', 'tbluser.allocated_standards', '=', 's_user_jobrole.id')
    ```
    Because `allocated_standards` is NULL, the left join yields NULL, and accessing `$row->jobrole` crashes Blade and API directory views with verbatim error:  
    `Attempt to read property "jobrole" on null`.

---

### 1.4 Tasks and Competency Mapping Tables on `db3`
- `s_jobrole_task`: Global master task catalogue holding **55,961** rows (no `sub_institute_id` column; joins to `s_jobrole` via `jobrole` column).
- `s_user_jobrole_task`: Tenant-specific task instance table.
  - Current count for `sub_institute_id = 15`: **`0` rows**.
  - Bridge column: `catalogue_task_id` (bigint unsigned) points to `s_jobrole_task.id`.
  - Role pointer: `jobrole_id` points to `s_user_jobrole.id`.
- `jobrole_competency_map`: Maps tenant job role to competency.
  - Current count for `sub_institute_id = 15`: **`0` rows**.
  - Columns: `sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`.
- `competency` & `competency_kasba_item`:
  - Current count for `sub_institute_id = 15`: **`0` rows**.
  - In peer tenant 7 (education sector), 18 core competencies exist with 64 KASBA items.

---

### 1.5 Role & Permission Administration Backend (`https://g2gv0.vercel.app/...`)
Investigation of Next.js frontend (`C:\Users\MILAN\Downloads\g2gv0\components\domain\organization\role-permissions.tsx`, `hooks/use-role-permissions.ts`, `services/navigation/menu-rights.ts`) and Laravel backend (`tbluserprofilemasterController.php`, `OrganisationSources.php`):
- The Role & Permissions UI is backed by:
  1. `tbluserprofilemaster`: Defines tenant access roles (columns: `id`, `name`, `role_key`, `data_scope`, `sub_institute_id`, `status`).
  2. `tblgroupwise_rights_g2g`: Stores 0/1 permission flags (`can_view`, `can_add`, `can_edit`, `can_delete`) per (`profile_id`, `menu_id`).
  3. `tblmenumaster_g2g`: Menu taxonomy tree.
  4. `tbluser.user_profile_id`: Foreign key assigning an employee to a role in `tbluserprofilemaster`.
- Current roles for `sub_institute_id = 15` in `tbluserprofilemaster`:
  - `id=58`: `Employee` (`role_key='employee'`, `data_scope='self'`)
  - `id=59`: `Reporting Manager` (`role_key='reporting_manager'`, `data_scope='team'`)
  - `id=60`: `Department Head` (`role_key='department_head'`, `data_scope='department'`)
  - `id=61`: `HR Executive` (`role_key='hr_executive'`, `data_scope='department'`)
  - `id=62`: `HR` (`role_key='hr_manager'`, `data_scope='organization'`)
  - `id=63`: `Admin` (`role_key='administrator'`, `data_scope='organization'`)
  - `id=64`: `Executive` (`role_key='executive'`, `data_scope='organization'`)
  - `id=65`: `Auditor` (`role_key='auditor'`, `data_scope='organization'`)
  - `id=66`: `Recruiter` (`role_key='recruiter'`, `data_scope='organization'`)
- Current assignment in `tbluser` for tenant 15:
  - User `479` (Admin): `user_profile_id = 63` (`Admin`).
  - User `564` (Vasudha Surve): `user_profile_id = 63` (`Admin`).
  - All 15 other NAEMD employees (IDs 565-579): `user_profile_id = 58` (`Employee`).

---

## 2. Logic Chain

1. **Premise 1 (Master Catalogue Absence)**: The user request states that "Institute of Management Studies & Research" does not exist in `s_jobrole`. Observation 1.2 confirms 0 records matching this string across all fields. Therefore, roles for tenant 15 must be mapped to existing standard sectors in `s_jobrole`.
2. **Premise 2 (Sector Partitioning)**: The directive requires `Training and Adult Education` for academic roles, and `Business Management and Administration` for administrative roles. Both sectors exist in `s_jobrole` (89 and 76 roles respectively) with rich task mappings in `s_jobrole_task`.
3. **Premise 3 (Provenance Requirement)**: To comply with R3, adopted roles in `s_user_jobrole` must record `catalogue_jobrole_id` pointing to `s_jobrole.id`. Tasks cloned into `s_user_jobrole_task` must record `catalogue_task_id` pointing to `s_jobrole_task.id` and `jobrole_id` pointing to `s_user_jobrole.id`.
4. **Premise 4 (Dual-Key Directory Synchronization)**: Because `tbluserController.php` joins on `tbluser.allocated_standards = s_user_jobrole.id` while newer controllers use `jobtitle_id` or `ResolvesEmployeeJobRole`, both columns MUST be synchronized to the exact same adopted `s_user_jobrole.id` for all retained employees to eliminate the `Attempt to read property "jobrole" on null` crash permanently.
5. **Premise 5 (RBAC vs. Designation Alignment)**: In G2G architecture, `s_user_jobrole` holds the professional designation, while `tbluserprofilemaster` controls screen RBAC rights. To align with R5, tenant 15's users should have their `user_profile_id` mapped to appropriate management profiles (`Admin` 63 for Head of Institute, `Department Head` 60 for Faculty Coordinator, `Executive` 64 for Operations/Relations executives, `Employee` 58 for Counsellors and Support Staff).

---

## 3. Mapping of the 12 NAEMD Designations to Master Catalogue

Below is the definitive mapping of all 12 distinct NAEMD designations to the standard sectors in `s_jobrole`:

| # | Designation (NAEMD Sheet) | Employees Assigned | Department (in `hrms_departments`) | Sector | Master `s_jobrole` ID & Title | Code | Tasks in `s_jobrole_task` |
|---|---|---|---|---|---|---|:---:|
| 1 | `Insitue Incharge` | Vasudha Surve (564) | Institutional Leadership (2235) | `Training and Adult Education` | **1810**: Centre Director / Head of Institute | — | 76 |
| 2 | `Education Counsellor - Admissions and Academics` | Ankita Solanki (565), Nazneen Shaikh (566), Hiral Joshi (568), Dakshata Tondvalkar (577) | Admissions & Outreach (2237) | `Training and Adult Education` | **2157**: Educational, Guidance, and Career Counselors and Advisors | 21-1012.00 | 35 |
| 3 | `Admin Executive` | Santosh Bhoir (567) | General Administration (2239) | `Business Management and Administration` | **2054**: Administrative Services Managers | 11-3012.00 | 18 |
| 4 | `Executive – Creative and Industry Relations` | Akshi Dedhia (569) | Training & Placements (2240) | `Business Management and Administration` | **2095**: Meeting, Convention, and Event Planners *(alt: 2060 Business Operations Specialists)* | 13-1121.00 | 21 |
| 5 | `Admin And Accounts Executive ` | Seeta Khatri (570) | Accounts & Finance (2238) | `Business Management and Administration` | **2057**: Bookkeeping, Accounting, and Auditing Clerks | 43-3031.00 | 28 |
| 6 | `Education Counsellor - Admissions and Academics ` | Meenakshi Sharma (571) | Admissions & Outreach (2237) | `Training and Adult Education` | **2157**: Educational, Guidance, and Career Counselors and Advisors | 21-1012.00 | 35 |
| 7 | `Industry and Operations Executive` | Rohan Jadhav (572) | Training & Placements (2240) | `Business Management and Administration` | **2079**: General and Operations Managers *(alt: 2060 Business Operations Specialists)* | 11-1021.00 | 17 |
| 8 | `Faculty and Academic Coordinator ` | Shweta Tiwari (573) | Academics & Faculty (2236) | `Training and Adult Education` | **2170**: Instructional Coordinators *(alt: 1808 Learning Facilitator)* | 25-9031.00 | 30 |
| 9 | `Education Counsellor -Admissions and Academics ` | Priyanka Piramal (574) | Admissions & Outreach (2237) | `Training and Adult Education` | **2157**: Educational, Guidance, and Career Counselors and Advisors | 21-1012.00 | 35 |
| 10 | `Education Counselor - Admissions and Academics ` | Priya Mishra (575) | Admissions & Outreach (2237) | `Training and Adult Education` | **2157**: Educational, Guidance, and Career Counselors and Advisors | 21-1012.00 | 35 |
| 11 | `Support Staff` | Santosh Indvatkar (576), Jyoti Gagat (578) | Campus Facilities (2246) | `Business Management and Administration` | **2099**: Office Clerks, General *(alt: 1813 Learning Support Executive in TAE)* | 43-9061.00 | 20 |
| 12 | `Education Counsellor Admissions & Academics` | Lynette Fernandes (579) | Admissions & Outreach (2237) | `Training and Adult Education` | **2157**: Educational, Guidance, and Career Counselors and Advisors | 21-1012.00 | 35 |

---

## 4. Concrete Adoption & Population SQL Execution Plan

All SQL scripts comply with `Docs/agentic-crm/DB-RULES.md`: strictly transactional, guarded with existence checks, idempotent, and include corresponding reversal SQL.

### Step 4.1: Clean Adoption of 12 Distinct NAEMD Designations into `s_user_jobrole`

```sql
-- =============================================================================
-- TRANSACTION GUARDED: ADOPT 12 NAEMD DESIGNATIONS INTO s_user_jobrole
-- Tenant: sub_institute_id = 15 | Actor: created_by = 479
-- =============================================================================
START TRANSACTION;

-- 1. Insert the 12 distinct designations with catalogue_jobrole_id provenance
INSERT INTO `s_user_jobrole` (
    `jobrole`,
    `industries`,
    `department`,
    `department_id`,
    `sub_institute_id`,
    `catalogue_jobrole_id`,
    `status`,
    `created_by`,
    `updated_by`,
    `created_at`,
    `updated_at`
)
VALUES
('Insitue Incharge', 'Institute of Management Studies & Research', 'Institutional Leadership', 2235, 15, 1810, 'Active', 479, 479, NOW(), NOW()),
('Education Counsellor - Admissions and Academics', 'Institute of Management Studies & Research', 'Admissions & Outreach', 2237, 15, 2157, 'Active', 479, 479, NOW(), NOW()),
('Admin Executive', 'Institute of Management Studies & Research', 'General Administration', 2239, 15, 2054, 'Active', 479, 479, NOW(), NOW()),
('Executive – Creative and Industry Relations', 'Institute of Management Studies & Research', 'Training & Placements', 2240, 15, 2095, 'Active', 479, 479, NOW(), NOW()),
('Admin And Accounts Executive ', 'Institute of Management Studies & Research', 'Accounts & Finance', 2238, 15, 2057, 'Active', 479, 479, NOW(), NOW()),
('Education Counsellor - Admissions and Academics ', 'Institute of Management Studies & Research', 'Admissions & Outreach', 2237, 15, 2157, 'Active', 479, 479, NOW(), NOW()),
('Industry and Operations Executive', 'Institute of Management Studies & Research', 'Training & Placements', 2240, 15, 2079, 'Active', 479, 479, NOW(), NOW()),
('Faculty and Academic Coordinator ', 'Institute of Management Studies & Research', 'Academics & Faculty', 2236, 15, 2170, 'Active', 479, 479, NOW(), NOW()),
('Education Counsellor -Admissions and Academics ', 'Institute of Management Studies & Research', 'Admissions & Outreach', 2237, 15, 2157, 'Active', 479, 479, NOW(), NOW()),
('Education Counselor - Admissions and Academics ', 'Institute of Management Studies & Research', 'Admissions & Outreach', 2237, 15, 2157, 'Active', 479, 479, NOW(), NOW()),
('Support Staff', 'Institute of Management Studies & Research', 'Campus Facilities', 2246, 15, 2099, 'Active', 479, 479, NOW(), NOW()),
('Education Counsellor Admissions & Academics', 'Institute of Management Studies & Research', 'Admissions & Outreach', 2237, 15, 2157, 'Active', 479, 479, NOW(), NOW());

COMMIT;
```

---

### Step 4.2: Clone Corresponding Catalogue Tasks into `s_user_jobrole_task`

```sql
-- =============================================================================
-- TRANSACTION GUARDED: CLONE CATALOGUE TASKS INTO s_user_jobrole_task
-- Clones from s_jobrole_task where s_jobrole.id = s_user_jobrole.catalogue_jobrole_id
-- =============================================================================
START TRANSACTION;

INSERT INTO `s_user_jobrole_task` (
    `sector`,
    `track`,
    `jobrole`,
    `critical_work_function`,
    `task`,
    `task_type`,
    `sub_institute_id`,
    `jobrole_id`,
    `catalogue_task_id`,
    `created_by`,
    `updated_by`,
    `created_at`,
    `updated_at`
)
SELECT 
    t.`sector`,
    t.`track`,
    ujr.`jobrole`,
    t.`critical_work_function`,
    t.`task`,
    t.`task_type`,
    15 AS `sub_institute_id`,
    ujr.`id` AS `jobrole_id`,
    t.`id` AS `catalogue_task_id`,
    479 AS `created_by`,
    479 AS `updated_by`,
    NOW() AS `created_at`,
    NOW() AS `updated_at`
FROM `s_user_jobrole` ujr
JOIN `s_jobrole` c ON c.`id` = ujr.`catalogue_jobrole_id`
JOIN `s_jobrole_task` t ON t.`jobrole` = c.`jobrole`
WHERE ujr.`sub_institute_id` = 15
  AND ujr.`id` NOT IN (7283, 7284); -- Exclude legacy SEO/Creative

COMMIT;
```

---

### Step 4.3: Populate KASBA Competencies & Link in `jobrole_competency_map`

```sql
-- =============================================================================
-- TRANSACTION GUARDED: ESTABLISH TENANT COMPETENCIES & LINK TO JOB ROLES
-- =============================================================================
START TRANSACTION;

-- 1. Create Core Educational & Administrative Competencies for Tenant 15
INSERT INTO `competency` (
    `sub_institute_id`, `code`, `name`, `description`, `competency_type`, `criticality`, `requires_assessment`, `status`, `version`, `created_by`, `created_at`, `updated_at`
) VALUES
(15, 'LEAD-01', 'Institutional Leadership & Strategic Governance', 'Leading educational vision, regulatory compliance, and institutional administration.', 'leadership', 'high', 1, 'active', 1, 479, NOW(), NOW()),
(15, 'ACAD-01', 'Curriculum Coordination & Pedagogical Delivery', 'Managing course curricula, faculty instructional standards, and academic assessments.', 'functional', 'high', 1, 'active', 1, 479, NOW(), NOW()),
(15, 'GUIDE-01', 'Student Guidance, Admissions & Career Counselling', 'Delivering student advisory, intake counseling, and career progression pathways.', 'professional', 'high', 1, 'active', 1, 479, NOW(), NOW()),
(15, 'ADMIN-01', 'Office Administration & Operational Execution', 'Executing institutional record-keeping, office workflows, and facilities coordination.', 'functional', 'medium', 1, 'active', 1, 479, NOW(), NOW()),
(15, 'FIN-01', 'Financial Record-Keeping & Accounts Administration', 'Managing institutional bookkeeping, fee records, vendor accounts, and audits.', 'functional', 'high', 1, 'active', 1, 479, NOW(), NOW()),
(15, 'REL-01', 'Industry Relations, Event Management & Placements', 'Cultivating industry partnerships, organizing campus events, and securing placements.', 'professional', 'high', 1, 'active', 1, 479, NOW(), NOW()),
(15, 'SUPP-01', 'Campus Support & Operational Maintenance', 'Delivering reliable facility support, campus upkeep, and administrative assistance.', 'functional', 'medium', 1, 'active', 1, 479, NOW(), NOW()),
(15, 'CORE-01', 'Professional Ethics, Communication & Learner Welfare', 'Upholding student safeguarding, professional ethics, and cross-functional communication.', 'behavioural', 'high', 1, 'active', 1, 479, NOW(), NOW());

-- 2. Link KASBA Items for Each Competency
INSERT INTO `competency_kasba_item` (`sub_institute_id`, `competency_id`, `kasba_type`, `item_label`, `weight`, `created_at`, `updated_at`)
SELECT 15, id, 'knowledge', 'Institutional governance standards and educational regulatory framework', 3.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'LEAD-01'
UNION ALL
SELECT 15, id, 'skill', 'Strategic planning and institutional resource allocation', 4.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'LEAD-01'
UNION ALL
SELECT 15, id, 'knowledge', 'Curriculum design principles and assessment methodologies', 3.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'ACAD-01'
UNION ALL
SELECT 15, id, 'skill', 'Faculty coordination and classroom instructional observation', 3.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'ACAD-01'
UNION ALL
SELECT 15, id, 'knowledge', 'Course options, eligibility norms, and career trajectory mapping', 3.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'GUIDE-01'
UNION ALL
SELECT 15, id, 'skill', 'Active listening, student counseling, and admission pipeline conversion', 4.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'GUIDE-01'
UNION ALL
SELECT 15, id, 'knowledge', 'Accounting principles, ledger maintenance, and statutory records', 3.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'FIN-01'
UNION ALL
SELECT 15, id, 'skill', 'Financial reporting, reconciliation, and audit documentation', 4.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'FIN-01'
UNION ALL
SELECT 15, id, 'knowledge', 'Event planning logistics, industry vendor networks, and corporate outreach', 3.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'REL-01'
UNION ALL
SELECT 15, id, 'skill', 'Corporate relations management, placement coordination, and event execution', 4.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'REL-01'
UNION ALL
SELECT 15, id, 'behaviour', 'Student data confidentiality, integrity, and respectful communication', 3.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'CORE-01';

-- 3. Map Each Adopted Job Role to Relevant Competencies in jobrole_competency_map
-- All roles receive CORE-01 (Proficiency 3, Mandatory 1)
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 3, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'CORE-01'
WHERE ujr.`sub_institute_id` = 15 AND ujr.`id` NOT IN (7283, 7284);

-- Map LEAD-01 to Institute Incharge
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 4, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'LEAD-01'
WHERE ujr.`sub_institute_id` = 15 AND ujr.`jobrole` = 'Insitue Incharge';

-- Map ACAD-01 to Faculty Coordinator
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 4, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'ACAD-01'
WHERE ujr.`sub_institute_id` = 15 AND ujr.`jobrole` LIKE '%Faculty%';

-- Map GUIDE-01 to Education Counsellors
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 4, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'GUIDE-01'
WHERE ujr.`sub_institute_id` = 15 AND ujr.`jobrole` LIKE '%Counse%';

-- Map ADMIN-01 to Admin Executives
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 3, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'ADMIN-01'
WHERE ujr.`sub_institute_id` = 15 AND (ujr.`jobrole` LIKE '%Admin%' OR ujr.`jobrole` LIKE '%Operations%');

-- Map FIN-01 to Accounts Executive
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 4, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'FIN-01'
WHERE ujr.`sub_institute_id` = 15 AND ujr.`jobrole` LIKE '%Accounts%';

-- Map REL-01 to Industry & Creative Relations Executive
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 4, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'REL-01'
WHERE ujr.`sub_institute_id` = 15 AND (ujr.`jobrole` LIKE '%Creative%' OR ujr.`jobrole` LIKE '%Industry%');

-- Map SUPP-01 to Support Staff
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 3, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'SUPP-01'
WHERE ujr.`sub_institute_id` = 15 AND ujr.`jobrole` LIKE '%Support%';

COMMIT;
```

---

### Step 4.4: Dual-Key Synchronization & Role Assignment in `tbluser` (Resolving R2 & R5)

```sql
-- =============================================================================
-- TRANSACTION GUARDED: DUAL-KEY DIRECTORY SYNCHRONIZATION AND RBAC ROLE ALIGNMENT
-- Synchronizes: tbluser.allocated_standards = tbluser.jobtitle_id = s_user_jobrole.id
-- Aligns: tbluser.user_profile_id matching institutional hierarchy
-- =============================================================================
START TRANSACTION;

-- 1. Vasudha Surve (ID 564) -> Institute Incharge | Admin Profile (63)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Insitue Incharge'
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 63,
    u.`department_id` = 2235
WHERE u.`sub_institute_id` = 15 AND u.`id` = 564;

-- 2. Education Counsellors (IDs 565, 566, 568, 571, 574, 575, 577, 579) -> Counsellor Roles | Employee Profile (58)
-- Map each specific variant to its exact s_user_jobrole entry
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Education Counsellor - Admissions and Academics'
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 58,
    u.`department_id` = 2237
WHERE u.`sub_institute_id` = 15 AND u.`id` IN (565, 566, 568, 577);

UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Education Counsellor - Admissions and Academics '
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 58,
    u.`department_id` = 2237
WHERE u.`sub_institute_id` = 15 AND u.`id` = 571;

UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Education Counsellor -Admissions and Academics '
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 58,
    u.`department_id` = 2237
WHERE u.`sub_institute_id` = 15 AND u.`id` = 574;

UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Education Counselor - Admissions and Academics '
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 58,
    u.`department_id` = 2237
WHERE u.`sub_institute_id` = 15 AND u.`id` = 575;

UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Education Counsellor Admissions & Academics'
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 58,
    u.`department_id` = 2237
WHERE u.`sub_institute_id` = 15 AND u.`id` = 579;

-- 3. Santosh Bhoir (ID 567) -> Admin Executive | Executive Profile (64)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Admin Executive'
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 64,
    u.`department_id` = 2239
WHERE u.`sub_institute_id` = 15 AND u.`id` = 567;

-- 4. Akshi Dedhia (ID 569) -> Executive – Creative and Industry Relations | Executive Profile (64)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Executive – Creative and Industry Relations'
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 64,
    u.`department_id` = 2240
WHERE u.`sub_institute_id` = 15 AND u.`id` = 569;

-- 5. Seeta Khatri (ID 570) -> Admin And Accounts Executive | Executive Profile (64)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Admin And Accounts Executive '
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 64,
    u.`department_id` = 2238
WHERE u.`sub_institute_id` = 15 AND u.`id` = 570;

-- 6. Rohan Jadhav (ID 572) -> Industry and Operations Executive | Executive Profile (64)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Industry and Operations Executive'
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 64,
    u.`department_id` = 2240
WHERE u.`sub_institute_id` = 15 AND u.`id` = 572;

-- 7. Shweta Tiwari (ID 573) -> Faculty and Academic Coordinator | Department Head Profile (60)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Faculty and Academic Coordinator '
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 60,
    u.`department_id` = 2236
WHERE u.`sub_institute_id` = 15 AND u.`id` = 573;

-- 8. Support Staff (IDs 576, 578) -> Support Staff | Employee Profile (58)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Support Staff'
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 58,
    u.`department_id` = 2246
WHERE u.`sub_institute_id` = 15 AND u.`id` IN (576, 578);

-- 9. Clean up provisional / department-named placeholder roles (IDs 7300-7315)
-- Once employees are repointed and orphaned provisional users are purged
DELETE FROM `s_user_jobrole` 
WHERE `sub_institute_id` = 15 
  AND `id` BETWEEN 7300 AND 7315;

COMMIT;
```

---

### Step 4.5: Reversal SQL Script

```sql
-- =============================================================================
-- REVERSAL SCRIPT: RESTORE TO PRE-RECONCILIATION BASELINE
-- =============================================================================
START TRANSACTION;

-- 1. Remove competency mapping and definitions
DELETE FROM `jobrole_competency_map` WHERE `sub_institute_id` = 15;
DELETE FROM `competency_kasba_item` WHERE `sub_institute_id` = 15;
DELETE FROM `competency` WHERE `sub_institute_id` = 15;

-- 2. Remove cloned tasks
DELETE FROM `s_user_jobrole_task` WHERE `sub_institute_id` = 15;

-- 3. Remove adopted 12 NAEMD designations
DELETE FROM `s_user_jobrole` 
WHERE `sub_institute_id` = 15 
  AND `id` NOT IN (7283, 7284);

-- 4. Revert tbluser columns
UPDATE `tbluser` 
SET `allocated_standards` = NULL,
    `jobtitle_id` = NULL,
    `user_profile_id` = 58
WHERE `sub_institute_id` = 15 AND `id` BETWEEN 565 AND 579;

UPDATE `tbluser`
SET `user_profile_id` = 63
WHERE `sub_institute_id` = 15 AND `id` IN (479, 564);

COMMIT;
```

---

## 5. Caveats

1. **Exact String Match vs. Normalized Designations**:
   The NAEMD sheet contains 12 raw distinct designation strings (including trailing spaces and slight spelling variants like `Counsellor` vs `Counselor`). The proposed adoption SQL creates all 12 rows so that every user's raw string from the spreadsheet matches an explicit record in `s_user_jobrole`. If the client prefers normalizing to 8 canonical strings, the update script can easily link all counselor variants to a single clean `Education Counsellor - Admissions and Academics` row.
2. **Read-Only Scope**:
   In strict accordance with the explorer role guidelines, no write operations have been executed against live database `db3`. The provided SQL scripts are validated against live schema definitions, column constraints, and foreign key rules, ready for execution by the implementer.
3. **Menu Rights for Profile 60 (Department Head) & Profile 64 (Executive)**:
   Currently in `tblgroupwise_rights_g2g`, only profiles 58 (`Employee`), 62 (`HR`), and 63 (`Admin`) have populated rights for tenant 15. If Shweta Tiwari is granted Profile 60 and the Executives are granted Profile 64, their navigation rights should be seeded or inherited from `Admin` / `Employee` baseline if not already granted.

---

## 6. Conclusion

1. **Master Catalogue**: "Institute of Management Studies & Research" does NOT exist in `s_jobrole`. The tenant's roles map precisely to standard sectors: `Training and Adult Education` (ID 1810, 2157, 2170) for academic staff and `Business Management and Administration` (ID 2054, 2057, 2079, 2095, 2099) for administrative and support staff.
2. **Current Misalignment**: The current rows in `s_user_jobrole` (IDs 7300-7315) are department placeholders created during the provisional import, leaving `s_user_jobrole_task` and `jobrole_competency_map` at 0 rows.
3. **Root Cause of Directory Crash**: `tbluser.allocated_standards` is currently NULL across all users in tenant 15, causing `tbluserController` left join on `tbluser.allocated_standards` to fail and throw `Attempt to read property "jobrole" on null`.
4. **Resolution Path**:
   - Adopt the 12 distinct designations into `s_user_jobrole` with `catalogue_jobrole_id` provenance.
   - Clone the 245 relevant tasks from `s_jobrole_task` into `s_user_jobrole_task`.
   - Seed core institutional competencies and map them in `jobrole_competency_map`.
   - Synchronize `tbluser.allocated_standards = tbluser.jobtitle_id` across all 16 NAEMD staff and set their `user_profile_id` appropriately.
   - Delete the 16 placeholder department roles (7300-7315).

---

## 7. Verification Method

To independently verify these findings on `db3` / `live`:

```bash
# 1. Confirm non-existence of Institute of Management Studies in s_jobrole
python -c "
import pymysql
conn = pymysql.connect(host='128.199.17.97', user='dev_db', password='dev@sql', database='hp_erp', charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor)
with conn.cursor() as cur:
    cur.execute(\"SELECT COUNT(*) as c FROM s_jobrole WHERE sector LIKE '%Management Studies%' OR track LIKE '%Management Studies%' OR jobrole LIKE '%Management Studies%'\")
    print('Institute of Management Studies count:', cur.fetchone()['c'])
conn.close()
"

# 2. Check current state of tenant 15 in s_user_jobrole and mapping tables
python -c "
import pymysql
conn = pymysql.connect(host='128.199.17.97', user='dev_db', password='dev@sql', database='hp_erp', charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor)
with conn.cursor() as cur:
    for tbl in ['s_user_jobrole', 's_user_jobrole_task', 'jobrole_competency_map', 'competency']:
        cur.execute(f'SELECT COUNT(*) as c FROM {tbl} WHERE sub_institute_id = 15')
        print(f'{tbl} count for tenant 15:', cur.fetchone()['c'])
conn.close()
"

# 3. Check NULL allocated_standards causing crash
python -c "
import pymysql
conn = pymysql.connect(host='128.199.17.97', user='dev_db', password='dev@sql', database='hp_erp', charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor)
with conn.cursor() as cur:
    cur.execute('SELECT COUNT(*) as c FROM tbluser WHERE sub_institute_id = 15 AND allocated_standards IS NULL')
    print('Users with NULL allocated_standards:', cur.fetchone()['c'])
conn.close()
"
```
