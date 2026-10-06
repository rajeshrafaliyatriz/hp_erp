# 07. Database Audit (DB3 only)

All schema facts in this document were captured directly against **[DB3 / live / 128.199.17.97]** (MariaDB 10.1.48) via Laravel's `live` connection — using `DESCRIBE`, `SHOW INDEX`, and `->count()` (no raw mysql CLI, no credential values printed). Every fact below carries the `[DB3 / live / 128.199.17.97]` tag inherited from the source evidence, reproduced verbatim. Raw evidence files are cited per section.

---

## 1. hr_attendance_leave

Raw DESCRIBE/count evidence: `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/schema-hr_attendance_leave-db3.txt`

| Table | PK | FK cols | Tenant col? | Soft delete? | Audit cols? | Row count [DB3 / live / 128.199.17.97] |
|---|---|---|---|---|---|---|
| hrms_attendances | id | user_id, attendance_log_id, client_id, sub_institute_id | sub_institute_id | Yes (deleted_at + deleted_by) | created_by/updated_by/created_at/updated_at | 1263 |
| hrms_attendance_regularisations | id | user_id, reviewed_by | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 0 |
| hrms_emp_leaves | id | user_id, leave_type_id, department_id | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 41 |
| hrms_leave_allocation | id | employee_id, department_id, leave_type_id | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 28 |
| hrms_leave_types | id | (none enforced; sub_institute_id only) | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 12 |
| hrms_leave_role_permissions | id | (none) | sub_institute_id | Yes (no deleted_by) | created_by/updated_by/timestamps | 27 |
| hrms_leave_workflow_settings | id | (none) | sub_institute_id (unique, 1/tenant) | Yes (no deleted_by) | created_by/updated_by/timestamps | 3 |
| hrms_leave_approval_steps | id | leave_id, workflow_id, approver_id/approver_user_id | sub_institute_id | No deleted_at column | created_at/updated_at only (no created_by/updated_by) | 41 |
| user_onboarding_status | id | user_id | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 2 |
| Onboarding_tour_details | id | menu_id | none | Yes (deleted_at only) | timestamps only, no created_by/updated_by | 537 |

**Notes on this DB3 host specifically:**

- **`hrms_leave_approval_steps` has two overlapping approver-identity column pairs** (`approver_id`/`approver_name` and `approver_user_id`/`approver_user_name`), plus both `status` and `decision`, and both `comment` and no distinct reviewer field — a migration-history artifact (columns from an earlier design were kept alongside a later rewrite) rather than a clean schema; worth confirming in code which pair is actually read.
- **`hrms_attendance_regularisations` has 0 rows on this host** — the feature exists in schema/code but has never been used on DB3/live, so it reads as present-but-dormant here, not confirmed duplicate/legacy.
- **`Onboarding_tour_details` has mixed-case table name** (capital O) and by far the largest row count (537) of the group, plus no `created_by`/`updated_by`/tenant column at all — it's an event/analytics-style log (page visits, tour steps) rather than a config table, structurally the odd one out in this group.
- **`hrms_leave_types.leave_type_id` is `varchar`**, not the usual `unsignedBigInteger` FK pattern seen elsewhere — an inconsistent/legacy-looking column on this host.
- No genuinely duplicate/dead table was found among the 10 — `hrms_leave_workflow_settings` and `hrms_leave_role_permissions` are distinct (settings vs. per-role permission matrix), and `hrms_leave_approval_steps` is the per-request instantiation of the workflow config, not a duplicate of it.

---

## 2. talent_competency

Evidence saved to `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/schema-talent_competency-db3.txt`.

| Table | PK | FK cols | Tenant col? | Soft delete? | Audit cols? | Row count [DB3 / live / 128.199.17.97] |
|---|---|---|---|---|---|---|
| talent_job_postings | id | department_id, jobrole_id | sub_institute_id | Yes | created_by/updated_by/deleted_by/created_at/updated_at | 133 |
| talent_job_applications | id | job_id, candidate_id | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 281 |
| talent_candidates | id | — | sub_institute_id | Yes | created_by/updated_by/timestamps | 216 |
| talent_interview_schedules | id | job_id, applicant_id, panel_id | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 144 |
| talent_offers | id | application_id, job_id | sub_institute_id | No | created_by only, no updated_by/deleted_at | 71 |
| talent_screening_results | id | candidate_id | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 286 |
| s_competency_frameworks | id | department_id, jobrole_id | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 35 |
| s_competency_assessments | id | framework_id, cycle_id, attempt_id, user_id, department_id, jobrole_id | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 142 |
| s_competency_development_plans | id | user_id, competency_id, framework_id, career_path_id, department_id, jobrole_id, approver_id | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 165 |
| s_competency_certifications | id | user_id, competency_id, requirement_id, department_id, jobrole_id, certification_type_id | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 222 |
| competency_rating_history | id | user_id, kasba_item_id, competency_id, course_id | sub_institute_id | No (append-only, no updated_at either) | created_at only | 4 |
| competency_kasba_rating | id | user_id, kasba_item_id, cycle_id | sub_institute_id | No | created_at/updated_at, no created_by | 262 |
| s_users_skills | id | catalogue_skill_id | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 4466 |
| s_skill_matrix | id | user_id, skill_id | **none** | Yes | created_by/updated_by/deleted_by/timestamps | 169 |
| s_user_skill_application | id | skill_id | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 577 |
| s_jobrole_task | id | — | **none** | No | none at all | 55,961 |
| s_user_jobrole_task | id | jobrole_id, catalogue_task_id | sub_institute_id | Yes | created_by/updated_by/deleted_by/timestamps | 91,540 |
| jobrole_task_execution | id | user_jobrole_task_id, catalogue_task_id, eso_template_id, classified_by, reviewed_by | sub_institute_id | No | classified_by/reviewed_by + timestamps | 3,572 |
| eso | id | catalogue_task_id, user_jobrole_task_id, eso_template_id | sub_institute_id (nullable) | Yes | created_by/updated_by/timestamps | 9 |
| task_automation_classification | — | — | — | — | — | **does not exist** [DB3 / live / 128.199.17.97] |

**Notes on duplicate/legacy/unused, specific to DB3:**

- `task_automation_classification` — confirmed absent [DB3 / live / 128.199.17.97]. It was the previous attempt at `jobrole_task_execution`'s job, dropped by its own migration because it was already empty and unreferenced; nothing to flag going forward.
- `competency_rating_history` has only 4 rows [DB3 / live] vs `competency_kasba_rating`'s 262 — the history/audit trail for rating changes is effectively not being populated yet even though the current-value table is in active use.
- `s_skill_matrix` (169 rows) has no `sub_institute_id` at all — it is the one table in this group not tenant-scoped, worth flagging as a multi-tenancy gap rather than legacy/dead.
- `eso` has only 9 rows [DB3 / live] against a `jobrole_task_execution` table of 3,572 — the ESO template/instance layer looks newly seeded/early-stage relative to its sibling table, not yet dead.
- `s_jobrole_task` (55,961 rows) and `s_user_jobrole_task` (91,540 rows) are catalogue vs. tenant-copy pairs by design (documented in the `jobrole_task_execution` migration), not duplicates.

All facts above are tagged [DB3 / live / 128.199.17.97] per the audit rules; no facts required a "NOT VERIFIED" fallback except the confirmed non-existence of `task_automation_classification`.

---

## 3. payroll

Tables identified via grep of `app/Http/Controllers/Payroll/PayrollController.php` cross-referenced against `database/migrations`. Confirmed against DB3 by `DESCRIBE` + `->count()` over `DB::connection('live')`.

| Table | PK | FK cols (by convention) | Tenant col? | Soft delete? | Audit cols? | Row count [DB3 / live / 128.199.17.97] |
|---|---|---|---|---|---|---|
| `employee_monthly_salary_data` | `id` | `employee_id`, `created_by`, `updated_by`, `deleted_by` | `sub_institute_id` | Yes (`deleted_at`) | Yes (`created_by`/`updated_by`/`created_at`/`updated_at`) | 6 |
| `payroll_types` | `id` | `created_by`, `updated_by`, `deleted_by` | `sub_institute_id` | Yes (`deleted_at`) | Yes | 13 |
| `hrms_emp_payroll_deduction` | `id` | `employee_id`, `deduction_type` (see note), `created_by`, `updated_by`, `deleted_by` | `sub_institute_id` | Yes (`deleted_at`) | Yes | 12 |
| `hrms_salary_certificate` | `id` | `department_id`, `employee_id`, `created_by`, `updated_by`, `deleted_by` | `sub_institute_id` | Yes (`deleted_at`) | Yes | 0 |
| `payroll_month_locks` | `id` | `locked_by`, `reopened_by` | `sub_institute_id` | No (no `deleted_at`; reopen tracked in-row instead) | Partial (`created_at`/`updated_at` only, no `created_by`) | 0 |

**Notes / anomalies on DB3 specifically:**

- **`hrms_emp_payroll_deduction`** — the migration file (`2025_09_11_053252_hrms_emp_payroll_deduction.php`) declares the FK column as `deduction_type_id` (references `tbluser`, oddly — looks like a copy-paste FK target), but the live DB3 column is named `deduction_type` with no accompanying FK in the index list. Migration source and live schema disagree on this column's name.
- **`hrms_salary_certificate`** and **`payroll_month_locks`** both have **0 rows** on DB3 — `payroll_month_locks` is expected (it's a new, month-lock feature not yet exercised), but a 0-row `hrms_salary_certificate` on a host meant to reflect real payroll activity (13 payroll_types, 12 deductions, 6 salary rows already present) suggests this feature path is either unused so far or its data lives only on a different host — not verified beyond DB3's own count.
- **`employee_monthly_salary_data`** carries the composite `UNIQUE (sub_institute_id, employee_id, year, month)` index added by the 2026-09-07 canonicalisation migration — confirmed present via `SHOW INDEX` on DB3, so duplicate-payslip prevention is live on this host.
- No table in this group looks like a legacy/duplicate leftover on DB3 itself (row counts are small but consistent with the app's current write paths); none had zero columns or were missing entirely.

Full raw DESCRIBE/count/index output saved to: `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/schema-payroll-db3.txt`

---

## 4. lms

Host: DB3 / live / 128.199.17.97 (MariaDB 10.1.48)

| Table | PK | FK cols | Tenant col? | Soft delete? | Audit cols? | Row count [DB3 / live / 128.199.17.97] |
|---|---|---|---|---|---|---|
| `lms_course_enroll` | `id` (bigint unsigned, AI) | `user_id`, `course_id` (both bigint unsigned, non-null, no DB-level FK constraint — MUL index only) | `sub_institute_id` — present but **int(11) NULLABLE**, not enforced | `deleted_at` column exists, but the `LmsCourseEnroll` model does **not** use `SoftDeletes` — column is populated (if at all) manually, not by Eloquent | `created_at`/`updated_at` (timestamp, nullable) only — **no** `created_by`/`updated_by` | **1500** [DB3 / live / 128.199.17.97] |
| `lms` | NOT VERIFIED — table does not exist on this host | — | — | — | — | table absent [DB3 / live / 128.199.17.97] |
| `lms_data_content_neo4j` | NOT VERIFIED — table does not exist on this host | — | — | — | — | table absent [DB3 / live / 128.199.17.97] |

**Existence check** — `information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('lms','lms_course_enroll','lms_data_content_neo4j')` returned only `lms_course_enroll` [DB3 / live / 128.199.17.97]. `DESCRIBE lms` and `DESCRIBE lms_data_content_neo4j` both errored `1146 Base table or view not found` on this connection. The `LmsDataContentNeo4j` Eloquent model (`app/Models/LmsDataContentNeo4j.php`, `$table = 'lms_data_content_neo4j'`) is live in code but points at a table that simply isn't on DB3 — either it lives only on the app's other/default DB connection, was never migrated to this host, or is dead code. Same status for a bare `lms` table: no migration in `database/migrations` ever does `Schema::create('lms', ...)`, so its absence here isn't a migration gap, it's that no such table was ever meant to exist by that exact name — "lms" is the module/group name, not a table.

**Duplicate/legacy note on `lms_course_enroll`**: the migration `2026_07_28_000000_create_lms_course_enroll_table.php` is a deliberately backdated, existence-guarded no-op — the repo has no migration that originally created this table; it was created directly on both DBs outside migration history. Its own comment (verified against DB3's DESCRIBE above) documents that DB3/live carries a looser schema than a fresh DB would get: `sub_institute_id` is nullable `int(11)` (fresh-DB definition would be `NOT NULL BIGINT`), there is **no unique key** on `(user_id, course_id)`, and live is said to hold 3 duplicate enrollment pairs — consistent with the DESCRIBE showing only a non-unique composite index. Application code (`EnrolmentWriter`, per the migration comment) compensates by taking the latest non-deleted row rather than relying on DB uniqueness.

Evidence file (raw DESCRIBE/count/error output): `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/schema-lms-db3.txt`

---

## 5. platform_ai

Grepped `app/Models` and `database/migrations` for platform (scheduler/workflow/event bus/process) and `ai_*`/`mcp_*`/`knowledge_*` tables. No `mcp_*` tables exist anywhere in the codebase. Only `knowledge_*`-adjacent table is `s_skill_knowledge_ability` (HR skills feature via `userKnowledgeAbility` model) — name doesn't match `knowledge_*` and it isn't part of the AI/platform stack, so excluded. Checked 31 candidate tables for existence with `Schema::connection('live')->hasTable()`; 20 exist [DB3 / live / 128.199.17.97]. Full DESCRIBE + counts saved to `Docs/cross-repo-audit/_evidence/schema-platform_ai-db3.txt`.

| Table | PK | FK cols | Tenant col? | Soft delete? | Audit cols? | Row count [DB3 / live / 128.199.17.97] |
|---|---|---|---|---|---|---|
| g2g_platform_scheduled_tasks | id | — | sub_institute_id | No | updated_by only | 1 |
| g2g_platform_task_runs | id | — | sub_institute_id (nullable) | No | none | 2 |
| g2g_platform_workflows | id | — | sub_institute_id | No | created_by/updated_by/timestamps | 3 |
| g2g_platform_workflow_versions | id | workflow_id | sub_institute_id | No | changed_by/created_at | 3 |
| g2g_process | id | — | sub_institute_id | No | created_by/updated_by/timestamps | 2 |
| g2g_process_task | id | process_id, task_id, assignee_id | sub_institute_id | No | timestamps only | 4 |
| g2g_process_version | id | process_id | sub_institute_id | No | changed_by/created_at | 0 |
| g2g_integration_credentials | id | — | sub_institute_id | No | created_by/updated_by/timestamps | 2 |
| g2g_event | id | entity_id, actor_id, acting_for_id | sub_institute_id | No (append-only) | occurred_at/recorded_at | 346 |
| g2g_event_delivery | id | event_id | none | No | none | 61 |
| g2g_audit_log | id | event_id, entity_id, actor_id, acting_for_id | sub_institute_id | No | occurred_at | 51 |
| ai_daily_used_api | id | parent_id | sub_institute_id | No | timestamps | 194 |
| ai_course_outlines | id | course_id, created_by, updated_by, deleted_by | sub_institute_id | **Yes** (deleted_at/deleted_by) | full | 62 |
| ai_api_keys | id | — | sub_institute_id | No | timestamps | 0 |
| ai_models | id | — | sub_institute_id | No | timestamps | 0 |
| ai_modules | id | menu_id, client_id | sub_institute_id | No | timestamps | 9 |
| ai_policies | id | created_by/updated_by | sub_institute_id | No | full | 0 |
| ai_policy_assignments | id | policy_id, scope_id | sub_institute_id | No | created_by/created_at | 0 |
| ai_generated_reports | id | client_id, layout_template_id | sub_institute_id | No | created_by/created_at | 0 |
| ai_module_model_bindings | id | api_key_id | sub_institute_id | No | full | 0 |

**Notes on DB3-specific state (duplicate/legacy/unused):**

- 11 code-present AI tables do **not exist** on DB3: `ai_templates`, `ai_suggestions`, `ai_policy_rules`, `ai_policy_acknowledgements`, `ai_audit_logs`, `ai_conversations`, `ai_conversation_turns`, `ai_evaluations`, `ai_evaluation_cases`, `ai_usage_events`, `ai_usage_quotas` — migrations exist in the repo but were never run against this host, confirming DB3 is a separately/behind-migrated host.
- `ai_api_keys`, `ai_models`, `ai_policies`, `ai_policy_assignments`, `ai_generated_reports`, `ai_module_model_bindings` all exist but have **0 rows** on DB3 — schema deployed, feature unused here.
- `g2g_audit_log` (51 rows) is a brand-new projection table; its own migration's comment notes two pre-existing independent audit writers (`task_management_audit_logs`, `tbl_user_journey_logs`) not covered by this table — three parallel audit surfaces now coexist, not yet reconciled, specifically on this host.

Evidence file: `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/schema-platform_ai-db3.txt`

---

## 6. menu_rights

All facts below are [DB3 / live / 128.199.17.97], captured via `DESCRIBE` and `->count()` through Laravel's `live` connection (no raw mysql CLI used, no credentials printed). Full raw output saved to `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/schema-menu_rights-db3.txt`.

| Table | PK | FK cols | Tenant col? | Soft delete? | Audit cols? | Row count [DB3 / live / 128.199.17.97] |
|---|---|---|---|---|---|---|
| tblmenumaster | id | parent_id | sub_institute_id (text) | deleted_at | created_at/updated_at (no created_by/updated_by) | 196 |
| tblmenumaster_g2g | id | parent_id | sub_institute_id (text) | deleted_at | created_at/updated_at (no created_by/updated_by) | 199 |
| tblgroupwise_rights | id | menu_id, profile_id | sub_institute_id (bigint) | deleted_at | created_at/updated_at (no created_by/updated_by) | 1328 |
| tblgroupwise_rights_g2g | id | menu_id, profile_id | sub_institute_id (text) | none | created_at only (no updated_at, no created_by/updated_by) | 4980 |
| tblindividual_rights | id | user_id, menu_id, profile_id | sub_institute_id (bigint) | deleted_at | created_at/updated_at (no created_by/updated_by) | 0 |
| tbluserprofilemaster | id | parent_id | sub_institute_id (bigint), client_id | deleted_at | created_at/updated_at (no created_by/updated_by) | 42 |
| tbluser | id | jobtitle_id, department_id, employee_id, reporting_manager_id, user_profile_id | sub_institute_id (bigint), client_id | deleted_at, deleted_by | created_at/updated_at/created_by/updated_by/deleted_by | 299 |

**Notes on duplication/legacy shape [DB3 / live / 128.199.17.97]:**

- **tblmenumaster vs tblmenumaster_g2g** — identical column sets (menu_name, parent_id, level, page_type, access_link, icon, status, sort_order, sub_institute_id, menu_type, deleted_at, timestamps), 196 vs 199 rows. This is a live parallel-catalogue pair, not dead legacy: the `_g2g` model carries recent doc-commented fixes (SoftDeletes added, a tenant-scope bug fixed in migration `2026_08_22_110000_make_g2g_menu_catalogue_global`), so `_g2g` looks like the actively-maintained catalogue while the base table trails it — worth confirming which one production code actually reads before assuming either is safe to drop.
- **tblgroupwise_rights vs tblgroupwise_rights_g2g** — same duplicate-pair pattern; the `_g2g` variant added `right_view/right_add/right_edit/right_delete/right_dashboard` enum('allow','deny') columns on top of the original `can_*` ints, has no `deleted_at` (no soft-delete) and only `created_at` (no `updated_at`), and outnumbers the original nearly 4:1 (4980 vs 1328 rows) — consistent with `_g2g` being the newer, more actively-written table.
- **tblindividual_rights** — fully provisioned schema (user-level override rights, both `can_*` and `right_*` columns, soft-delete, audit timestamps) but **0 rows** on DB3 — a scaffolded/unused feature on this host, not proof it's unused elsewhere.
- None of the seven tables has `created_by`/`updated_by` audit columns except `tbluser` (which also uniquely has `deleted_by`) — the rights/menu tables have no "who changed this row" trail at all.
- Tenant column shape is inconsistent within the group: `tblmenumaster*` and `tblgroupwise_rights_g2g` store `sub_institute_id` as `text` (comma-list semantics, per the g2g model's doc comments), while `tblgroupwise_rights`, `tblindividual_rights`, `tbluserprofilemaster`, and `tbluser` store it as a scalar `bigint`.

Evidence file: `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/schema-menu_rights-db3.txt`

---

## 7. organization

Full raw DESCRIBE/count output saved to `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/schema-organization-db3.txt`.

| Table | PK | FK cols (naming convention) | Tenant col? | Soft delete? | Audit cols? | Row count [DB3 / live / 128.199.17.97] |
|---|---|---|---|---|---|---|
| tblclient | id | — (root of hierarchy) | n/a (this IS the top-level client) | deleted_at | timestamps only, no created_by | 14 |
| school_setup | id | client_id → tblclient | acts as tenant itself (`sub_institute_id` elsewhere = this id) | deleted_at | timestamps only | 12 |
| org_details | id | sub_institute_id, created_by, updated_by | sub_institute_id (UNIQUE — 1:1 with tenant) | none | created_by/updated_by, timestamps | 12 |
| org_sister_details | id | org_id → org_details, sub_institute_id, created_by, updated_by | sub_institute_id (not unique) | none | created_by/updated_by, timestamps | 4 |
| hrms_departments | id | parent_id (self), head_user_id, sub_institute_id, created_by/updated_by/deleted_by | sub_institute_id | deleted_at | full (created_by/updated_by/deleted_by) | 1236 |
| hrms_departments_mapping | id | parent_id (self), sub_institute_id, created_by/updated_by/deleted_by | sub_institute_id | deleted_at | full | **0** |
| org_designation | id | user_id → tbluser, sub_institute_id, created_by/updated_by/deleted_by | sub_institute_id (NOT NULL) | deleted_at | full | 43 |
| institute_detail | id | sub_institute_id, created_by/updated_by/deleted_by | sub_institute_id | deleted_at | full | 5 |
| tenant_readiness_gate | id | sub_institute_id (unique w/ gate_key), acknowledged_by | sub_institute_id (NOT NULL) | none | timestamps only | 65 |
| department_sops | id | department_id, sub_institute_id, created_by | sub_institute_id | deleted_at | partial (no updated_by/deleted_by FK naming but cols exist) | 1 |
| department_policies | id | department_id, sub_institute_id, created_by | sub_institute_id | deleted_at | partial | **0** |
| department_rules | id | department_id, sub_institute_id, created_by | sub_institute_id | deleted_at | partial | **0** |

**Notes (DB3-specific):**

- `hrms_departments_mapping` [DB3 / live / 128.199.17.97] has 0 rows while `hrms_departments` has 1236 — near-identical schema (both carry `department`, `parent_id`, `tasks`, `roles_responsibility`, `sub_institute_id`). This strongly looks like a legacy/superseded duplicate of `hrms_departments` on this host, unused in practice.
- `department_policies` and `department_rules` are both 0 rows on DB3, and `department_sops` has only 1 — these three tables were added by a recent migration (`2026_08_21_091000`) specifically to replace mocked frontend data; on DB3 they exist structurally but are effectively unpopulated/unused so far.
- `tblclient`→`school_setup`→(`sub_institute_id` used everywhere else) is the real tenant chain on DB3; there is no separate table literally named "tenant" — `tenant_readiness_gate` is the only table using that word, and it keys off `sub_institute_id` like everything else.
- `org_details.sub_institute_id` is UNIQUE (one org profile per tenant) but `org_sister_details.sub_institute_id` is not unique, consistent with sister orgs being many-per-tenant via `org_id`.
- No table in this group failed to query; no "NOT VERIFIED" entries needed.

Evidence file: `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/schema-organization-db3.txt`
