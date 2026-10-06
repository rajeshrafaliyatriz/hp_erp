# 11. Multi-Tenancy Audit

No global scope tying `sub_institute_id` to auth was confirmed absent anywhere in `app/` — tenant filtering is manual, per-query, added by each controller.

## Multi-tenancy audit — cross-group synthesis [DB3 / live / 128.199.17.97]

### Tenant model

No global Eloquent scope enforces `sub_institute_id`/`client_id` anywhere in `app/` (grepped for `addGlobalScope`/`TenantScope`/`BelongsToTenant` — zero matches tying either column to the authenticated identity). Every controller checked (e.g. `LmsCourseEnrollController`, HRMS/Talent/Competency modules) applies tenant filtering manually via explicit `->where('sub_institute_id', ...)`.

This is a structural gap, not a bug: correctness depends on every query author remembering to add the clause, and the schema audits already surfaced several tables where nobody did.

### Tables with no tenant column at all [DB3 / live / 128.199.17.97]

| Table | Row count | Notes |
|---|---|---|
| `s_skill_matrix` | 169 | Per-user record (not a shared catalogue) — **the real concern** |
| `s_jobrole_task` | 55,961 | Catalogue table, tenant-neutral by design |
| `g2g_event_delivery` | 61 | No tenant column |
| `Onboarding_tour_details` | 537 | No tenant column |

Of these, `s_skill_matrix` is the real concern — it's a per-user record with 169 rows and no way to scope it by tenant at the DB layer; any query must join out to `s_users_skills`/`tbluser` to recover tenant context, or it leaks cross-tenant.

### Code-level check (LMS, the deepest-audited controller)

`LmsCourseEnrollController` derives `$subInstituteId` server-side from `resolveApiIdentity($request)` (token-derived), and — despite also accepting a client-supplied `sub_institute_id` field for validation — every read/write actually uses the identity-derived value, never `$request->sub_institute_id`.

`update()`/`destroy()` additionally verify both `course_id` and `user_id` belong to that tenant before acting (comments cite finding **F-71**: "both ends of the pair must be inside the caller's organisation"), and scope the row lookup by `id + user_id + sub_institute_id` together so an id alone can't reach another tenant's or another learner's row.

**This controller looks already remediated, not a live leak.**

### BFF layer (`g2gv0/services/organization/*.ts`)

All six call sites (`index.ts`, `employee-directory.ts`, `module-enablement.ts`, `setup-status.ts`) source `sub_institute_id` from a typed `LaravelContext` object built server-side, not from raw request query/body — no client-controlled override path found in this directory.

Real enforcement still lands on the Laravel side reading the bearer token, which this BFF forwards (`token: context.token`) rather than re-deriving.

### Residual risks worth flagging (not confirmed exploitable)

- **`s_skill_matrix`** — genuinely un-scoped table (noted in the original schema pass too).
- **`hrms_leave_approval_steps`, `g2g_event_delivery`, and several `ai_*`/`g2g_platform_*` tables** carry a tenant column but have no soft-delete/audit trail, so a manual-scoping mistake there would be silent and unrecoverable from the row itself.
- **`tblgroupwise_rights` vs `tblgroupwise_rights_g2g`** and **`tblmenumaster` vs `_g2g`** duplicate pairs store `sub_institute_id` in different types (bigint vs text/comma-list) across the pair — a scoping query written for one shape and run against the other table would silently under- or over-match.

### Evidence

- Prior schema files under `Docs/cross-repo-audit/_evidence/schema-*-db3.txt`
- Controller reviewed at `app/Http/Controllers/lms_course_enroll/LmsCourseEnrollController.php`
- BFF reviewed at `g2gv0/services/organization/*.ts`

No table/controller check errored; nothing required a "NOT VERIFIED" fallback.
