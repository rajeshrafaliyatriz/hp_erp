# Sprint 0 — Ground verification

No code was written in this phase. Its only purpose is to establish that the tooling can be trusted,
because a previous audit of this product reported a page of TypeScript errors that were artifacts of
its own broken `npm install`.

Pinned at `hp_erp` **a7d78c53** · `g2gv0` **a954e1a**. Run 2026-09-03.

## Tooling

| Check | Command | Result |
|---|---|---|
| Dependencies resolve | `npm ls --depth=0` | **exit 0** |
| Type baseline | `npx tsc --noEmit` | **exactly 7 errors** ✓ |
| Route table | `php artisan route:list --json` | **1847 routes, exit 0** |
| Design system untouched | `git diff --stat components/ui/` | **empty** |
| Working tree | `git status --porcelain` | g2gv0 clean; hp_erp 3 untracked docs only |

The 7 type errors are exactly the expected set — the baseline is real, not an install artifact:

```
components/domain/talent/administration/admin-center.tsx  222:23  TS2322  onValueChange not on SelectProps
components/domain/talent/administration/admin-center.tsx  222:39  TS7006  implicit any
lib/ai/backend/laravel-gateway.ts                          81:3   TS2739  LaravelContext missing orgType, profileId
packages/conversational-ai-core/src/conversation.ts        67:43  TS18046 'value' is unknown
services/talent/offboarding-service.ts                     15:107 TS2345  Record<string,string|number> vs Record<string,string>
services/talent/offboarding-service.ts                     40:120 TS2345  (same)
services/talent/offboarding-service.ts                     61:118 TS2345  (same)
```

Three of these live in the dead offboarding stack, so **the baseline legitimately drops to 4 in
Sprint 6**. The two in `admin-center.tsx:222` are a real defect in a file Sprint 3 already touches.

## Databases

Both reachable. Every row count in this work names its host.

| Connection | Host | Version | Tables |
|---|---|---|---|
| `mysql` (application default) | 202.47.117.220/hp_erp | 10.11.9-MariaDB-log | 434 |
| `live` | 128.199.17.97/hp_erp | 10.1.48-MariaDB | 288 |

Two engine facts that will govern every migration:

- **`Schema::hasTable()` throws on `live`.** Use the `information_schema` helper from
  `2026_09_01_101000_...:434-442` instead.
- All 26 `talent_*` tables are `ROW_FORMAT=Dynamic` on dev but **`Compact` on live** — a 767-byte
  index prefix cap that dev does not enforce. A migration can pass on dev and fail on live.

## Before-state evidence for Sprint 1

Captured against a locally served backend so the fix can be proven, not asserted.

**F-53 — candidate PII readable by any employee.** Token: profile `Employee`, `role_key=employee`,
`data_scope=self`, tenant 6.

```
GET /api/job-applications  ->  22 rows
exposed fields: first_name, last_name, email, mobile, expected_salary, resume_path
```

**Cross-tenant read of AI screening results — a live breach, executed.**

```
talent_screening_results candidate_id=18 is owned by tenant 3
GET /api/talent-screening-results/candidate/18  with a TENANT-6 token  ->  HTTP 200
```

404 is the correct answer. 200 means one organisation read another's screening verdict, skill gaps
and ranking score. `talent_screening_results_controller.php` filters by tenant in **none** of its four
queries (`:63, :69, :267, :295`) and persists a client-supplied `sub_institute_id` at `:52`.

Both commands are re-run at the end of Sprint 1 and must return 403 and 404 respectively.
