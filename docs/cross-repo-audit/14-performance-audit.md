# 14. Performance Audit

Scope: hp_erp (Laravel) + g2gv0 (Next.js). Findings are code-pattern based; DB3 facts are row counts only, no fresh schema pass was performed.

## 14.1 hp_erp: N+1 query patterns

### HRMS — `app/Http/Controllers/HRMS/HrmsController.php:1865-1882`

```php
foreach ($empData as $key => $value) {
    $newEmpData[] = $value;
    $getHlafDays = DB::table('hrms_emp_leaves')->whereRaw('id in (' . $ab . ')')->where('day_type', '0.5')->count();
    ...
    foreach ($getPunchTime as $punchkey => $punchvalue) {
        $getUserInTime = DB::table('tbluser')->where('id', $value->user_id)->value($dayName . '_in_date') ?? 0;
    }
}
```

A query-per-employee count, nested inside a query-per-punch-record lookup — effectively N×M queries. The whole controller has only **one** `->with()` call in its entire file, so eager loading is essentially absent across this 2700+ line controller. Similar loop-query patterns also appear at lines **2322-2343** and **2480-2513**.

### Talent — `app/Http/Controllers/talent/talent_jobpostingcontroller.php:575-583`

```php
foreach ($stages as $label => $criteria) {
    $query = DB::table('talent_job_applications as ja')->where(...)->whereNull('ja.deleted_at');
    if (isset($criteria['round'])) {
        $query->join('talent_interview_schedules as tis', 'ja.id', '=', 'tis.applicant_id')...
```

A fresh query is built and executed per pipeline stage rather than as a single grouped aggregate query.

### LMS — `app/Http/Controllers/lms/questionmasterController.php:527-531` and `:905-908`

```php
foreach ($lms_mapping_type as $lkey => $lval) {
    $arr = lmsmappingtypeModel::where(['parent_id' => $lval['id']])->get()->toArray();
```

```php
foreach ($mappedType as $key => $value) {
    $mappedValues[$key]->mappedValue = DB::table('lms_mapping_type')->whereRaw('id in (' . $value->mappedVal . ')')->get()->toArray();
```

Both are textbook per-row lookups that should instead be a single `whereIn`/grouped query.

### Index candidates worth checking

Code-pattern basis only — no fresh schema pass was run to confirm these indexes are missing.

| Table | Candidate columns |
|---|---|
| `hrms_emp_leaves` | `(user_id, day)` |
| `tbluser` | `(id)` — lookup-heavy |
| `lms_mapping_type` | `(parent_id)` |
| `talent_job_applications` | `(sub_institute_id, deleted_at)` |
| `talent_interview_schedules` | `(applicant_id)` |

## 14.2 DB3 facts (row counts only, per instructions — no fresh schema pass)

Connection: Laravel `live` → `config/database.php:125-139`, reads `DB3_*` env vars (variable names only, no values).

| Table | Row count | Source |
|---|---|---|
| `tbluser` | 299 | `[DB3 / live / 128.199.17.97]` |
| `hrms_emp_leaves` | 41 | `[DB3 / live / 128.199.17.97]` |
| `hrms_departments` | 1236 | `[DB3 / live / 128.199.17.97]` |
| `talent_job_applications` | 281 | `[DB3 / live / 128.199.17.97]` |
| `talent_interview_schedules` | 144 | `[DB3 / live / 128.199.17.97]` |
| `lms_question_master` | 381 | `[DB3 / live / 128.199.17.97]` |
| `lms_mapping_type` | 56 | `[DB3 / live / 128.199.17.97]` |

Counts are small — this DB3/live host looks like a low-volume/staging dataset, not confirmed to match production `DB_*` volume (per memory, "live" is not necessarily the host the app actually uses).

## 14.3 g2gv0: request waterfalls & memoization

Most data hooks already batch with `Promise.all` — a good pattern, e.g. `hooks/use-course-catalog.ts:129` and `hooks/use-course-builder.ts:280,456-464`.

### Waterfall found — `hooks/use-lms-dashboard.ts:259-343`

The main dashboard data loads via `Promise.all` (lines 260-278), but immediately after, two further calls are each awaited **sequentially** even though neither depends on the `Promise.all`'s results:

- `sessionWindow` (line 295): `await lmsSessionService.list(...)`
- `peerComparison` (line 341): `await lmsDashboardService.getPeerComparison(context)`

Both could be added to the same `Promise.all` batch, saving 2 extra round-trips per dashboard load. Comments at lines 289-293 and 337-339 justify isolating failure domains, but that doesn't require serial awaiting — isolation can be kept with `Promise.allSettled` instead.

### Memoization

Broad usage across the hooks layer:

| Metric | Count | Scope |
|---|---|---|
| `useMemo`/`useCallback` | 496 occurrences | 48 hook files |
| `useEffect` | 165 occurrences | 38 hook files |

No `useSWR`/React Query anywhere in `hooks/`, so caching/refetch-on-render is hand-rolled per hook rather than via a shared cache layer. This is a latent risk for duplicate fetches when multiple components mount the same hook, but was not observed as an active bug in the sampled files.
