# 04. Role & Permission Audit

Evidence saved to `Docs/cross-repo-audit/_evidence/roles-permissions-db3.txt`.

## DB3 Facts [DB3 / live / 128.199.17.97]

Connection verified: `config('database.connections.live.host')` = `128.199.17.97`, `select version()` = `10.1.48-MariaDB` (matches expected).

### `tbluserprofilemaster.role_key`

- Schema: `varchar(50)`, nullable, **no FK / no enum constraint**. [DB3 / live / 128.199.17.97]
- Of 42 total profile rows:

| Value | Count |
|---|---|
| NULL | 30 |
| Empty string | 0 |
| Populated | 12 |

- **Per-tenant (`sub_institute_id`) breakdown**: only tenants **1** (3/3 rows populated) and **6** (9/9 rows populated) have `role_key` set. The other **10 of 12 tenants are 100% NULL**.
- Populated values map cleanly to the nine documented role keys: `administrator`, `hr_manager`, `employee`, plus tenant 6's `auditor`, `executive`, `hr_executive`, `department_head`, `reporting_manager`, `recruiter` — all with `is_system=1`.
- **Conclusion**: `role_key` is real and correctly shaped where present, but sparsely seeded — most tenants have no machine-readable role identity yet, only the human-editable `name` column.

### `tbluser.status`

- Schema: `tinyint(1)`, default `1` — confirmed integer, never a string. [DB3 / live / 128.199.17.97]
- Of 299 users:

| Value | Count |
|---|---|
| `status = 1` | 288 |
| `status = 0` | 11 |

- Distinct values are exactly `{0, 1}`.
- **Reproduced the known trap empirically**: `where status = 'active'` returns **11 rows** — identical to the `status=0` count — because MariaDB casts the string to numeric `0` for comparison, silently matching the *disabled* users.
- **Correct query**: `status = 1 and deleted_at is null` → 188 active-not-deleted rows (note: 100 of the `status=1` rows are soft-deleted).

Raw query output saved to `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/roles-permissions-db3.txt`.

## Backend Enforcement (hp_erp, server-side, verified by reading code)

Real, layered middleware exists — this is **not** a rubber stamp:

| Middleware | Mechanism | Failure mode |
|---|---|---|
| `RequireProfile` / `profile:x` | Resolves caller from Sanctum token → `role_key`, exact-match only (substring matching was a past bug, since fixed) | Fail-closed: refuses on unresolvable role |
| `RequireMenuRight` / `menuright:id,action` | Consults `tblgroupwise_rights_g2g` with a documented DENY-precedence model: individual deny > group deny > individual allow > group allow > default deny | Enforced only per-route where applied — 653 routes still unmapped per the code's own comment |
| `TaskPermissionMiddleware` | Role-key-based | Fail-closed: an unresolvable profile is now refused, not granted |
| `RequirePlatformOwner` | Separate `platform_owners` table, re-authenticates independently | Returns 404, not 403 |

**Coverage check**: 229 of 1357 `Route::` definitions across the route files carry an explicit role/permission middleware token (`profile:`, `hrit.role:`, `menuright:`, `task.permission:`, `platform.owner`) — roughly **17%** directly declared. Some additional routes may inherit protection via `Route::group` wrapping not captured by this grep.

## Frontend Gating (g2gv0) — Assessed as Real, Not Decorative

- `types/role.ts` defines `ROLE_GROUPS` / `isHrAdmin` explicitly mirroring `App\Support\RoleKey::ALIASES` on the backend (a comment in the file states this intent directly).
- **Spot-checked case**: `admin-center.tsx` / `my-tasks-view.tsx` gate the "AI Stack" tab on `ROLE_GROUPS.admin`, with an inline comment noting it matches `/api/ai/*` (`profile:admin`) — confirmed `routes/ai.php` does carry `profile:admin` 3×.
- Similarly, `services/organization/role-permissions.ts` (the role-permissions editor UI) calls `save_groupwiserights_g2g`, confirmed server-gated with `->middleware('profile:admin')` in `routes/user-api.php`. The read endpoints (`ajax_groupwiserights_g2g`, `ajax_user_profiles_g2g`) only require `api.token` — any authenticated user can **read** the rights matrix, only admins can **write** it.

**Caveat**: `types/role.ts` also ships a separate hardcoded `MATRIX` (access levels per page for Organization Setup screens) that was **not** traced to a corresponding backend gate — **NOT VERIFIED** whether those specific pages have server-side enforcement or rely on the UI alone.

## Bottom Line

The audited slice of frontend gating is UI convenience layered on real, independently-enforced backend rules — not decoration. However, overall coverage is partial:

- Menu-right enforcement is opt-in per route (653 routes unmapped).
- Most tenants (10 of 12) lack `role_key` entirely.
- One frontend permission matrix (`MATRIX` in `types/role.ts`) was not traced to a backend counterpart.
