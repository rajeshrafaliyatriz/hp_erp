# Generic Report Builder Engine — Task Management first, then CRM

> Durable copy of the approved plan, committed to the repo so it survives a
> session reset. The Claude-side ephemeral plan file this was written from no
> longer matters once this exists — **this file is the source of truth going
> forward.** Update the checkboxes below as phases land; do not let this file
> drift from reality.

## Context

The user asked to add CRM's full Reports area (All Reports, Activity,
Campaign, Email, Invoice, Lead, Opportunity, Organization and Contact,
Product, Purchase Order, Quote, Sales Order, Tickets, Triz, Shared With Me),
explicitly rejecting the "simple/KPI-card" design of today's CRM Reports and
demanding the same no-feature-left-behind rigor as the earlier Sales/
Marketing/Support CRM migrations.

Investigation (3 parallel Explore agents + a direct read of the actual legacy
CRM source at `C:\Users\MILAN\Downloads\crm`, a real vtiger install for this
same client, "Triz") found:

- **Today's CRM "Reports" is a 3-day-old, 2-file wrapper**
  (`crm-reports-view.tsx` → `ModuleAiStackConsole`) around the generic,
  admin-curated, table-only AI Stack report engine
  (`App\Domain\AI\Reports\ModuleDataSourceCatalog`), with only 5 of 15+
  needed data sources defined, and no chart rendering anywhere in that
  engine. This is the "too simple" result the user is reacting to — it was
  explicitly scoped that way at the time ("reuse the generic engine, no new
  report UI"), not an oversight.
- **The real vtiger Reports module**
  (full source at `C:\Users\MILAN\Downloads\crm\modules\Reports`) is a
  genuine end-user report builder: 4 report types (tabular/summary/pivot/
  chart), 4 chart types (Pie/Vertical Bar/Horizontal Bar/Line, max 3 data
  fields for bar/line), arbitrary column/filter/grouping selection across
  joined modules, and a real sharing model (owner, Private/Public, explicit
  share to users/groups/roles). "All Reports" and "Shared With Me" are
  **computed views**, not real folders — confirmed via
  `Folder.php::checkDuplicate()`, which explicitly reserves those two names
  as unsavable. "Triz Reports" is **not a stock vtiger concept at all** —
  it's an ordinary custom folder this client created themselves; it needs no
  special content, just to exist as a normal user-creatable folder.
- Asked directly, the user chose the **full generic engine** (not a
  curated-screens-only version), chose to **build the missing CRM Activity
  entity now** (not skip Activity Reports), and chose to build this **for
  Task Management first, then CRM**, as one combined, fully-detailed,
  durably-saved plan.

## Decisions locked in

1. **One new, genuinely generic report engine**, not a CRM-specific or
   Task-Management-specific one and not an extension of the existing
   admin-curated `ModuleDataSourceCatalog` (new namespace
   `App\Domain\Reports\*`, sibling to `App\Domain\AI\Reports`, deliberately
   separate — different engine, different purpose: user-configurable, not
   admin-curated-for-AI-templates).
2. **Module/field registry is hand-authored PHP metadata, not DB
   introspection** — same reasoning vtiger itself follows (an admin/
   install-time curated field list, not "expose every raw column"), and the
   only way to keep a user-configurable column/filter picker from being a
   SQL-injection-shaped surface or leaking internal columns (`deleted_by`,
   raw FK ids, etc.).
3. **Persistence is JSON-first, not over-normalized** — `reports.definition`
   (one JSON column) carries columns/filters/sort/group/pivot/chart config;
   only folders, the report header (type/owner/sharing), and the sharing
   pivot get real relational tables. This deliberately avoids vtiger's own
   10+ table sprawl (`vtiger_selectcolumn`, `vtiger_relcriteria`,
   `vtiger_relcriteria_grouping`, `vtiger_reportsortcol`, ...) for the same
   data.
4. **"All Reports" and "Shared With Me" are computed queries, never real
   folders** — matches vtiger exactly (`Folder.php` reserves both names).
   Sharing reuses this codebase's own existing pattern
   (`DocumentShareService`'s share/unshare/listShares/canEdit shape,
   `CalendarShareService`'s `outgoing()`/`incoming()` naming — `incoming()`
   *is* "Shared With Me").
5. **Custom fields (`tblcustom_fields`/`g2g_custom_field_values`) are
   included as display-only tabular columns in v1**, never as filter/group/
   aggregate inputs — the EAV value column is untyped `TEXT`, and casting it
   for aggregation risks silent wrong numbers (e.g. a bad `CAST` on an empty
   string corrupting a SUM). Merged into row results with one extra keyed
   lookup query per report run, not one join per field.
6. **A new, minimal `crm_activities` table is in scope**, built in its own
   phase before CRM's module registration — "Activity Reports" has no real
   data to report on otherwise (confirmed: zero CRM-activity schema exists
   today, only a loose project-level FK to generic Task Management).
7. **Phase order is Task Management first** (the proving ground: pivot joins
   via a real pivot table, date-filtered + picklist-grouped summaries, chart
   rendering), **then the new CRM Activity entity, then CRM's full module
   registration** — not built simultaneously, so neither dilutes the other's
   quality.
8. **No KPI-card grid anywhere in this feature.** Charts (recharts, already a
   dependency) are the primary visual for chart-type reports; summary/pivot
   reports are grouped/matrix tables; any contextual numbers are a compact
   caption, never a `KPICard` tile grid.
9. **CRM's existing `/module/crm/support/reports` AI Stack screen is left
   exactly as-is** — it's a different, legitimate feature (ad hoc AI-template
   reports), not something this replaces. The new engine's CRM entry point is
   its own **new, top-level menu item under CRM root** (sibling to Marketing/
   Sales/Support), not nested under Support — vtiger's own Reports is a
   top-level tab, and burying it under Support would undercut the prominence
   the user is asking for.
10. **Task Management's existing flat Reports screen (`tm-reports.tsx` —
    Productivity/Delays/Learning tabs) is superseded** by the new shell,
    scoped to 3 folders: Task Reports, Events Report, Assigned Tasks Report
    (vtiger itself has no structural difference between "Task Reports" and
    "Assigned Tasks Report" beyond which default reports/grouping ship in
    each — this is a folder/seed-data distinction, not a registry one).

## Architecture

### 1. Module/field registry

`App\Domain\Reports\ReportModuleRegistry` — merges per-domain `definitions()`
arrays (mirrors how `ModuleDataSourceCatalog::definitions()` concatenates
`$core` + `SourceRegistry::definitions()`).

```php
final class ReportModuleRegistry
{
    public function all(): array;                                    // module key => ReportModuleDefinition
    public function get(string $moduleKey): ReportModuleDefinition;   // throws if unknown
    public function joinableFrom(string $primaryModuleKey): array;    // array<string> module keys
    public function fieldsFor(string $moduleKey, int $subInstituteId): array;       // + tenant's custom fields folded in
    public function dateFieldsFor(string $moduleKey, int $subInstituteId): array;
    public function groupableFieldsFor(string $moduleKey, int $subInstituteId): array;
    public function numericFieldsFor(string $moduleKey, int $subInstituteId): array; // aggregatable
}
```

Value objects: `ReportModuleDefinition` (key, label, table, alias,
tenantColumn, softDeleteColumn, primaryKey, fields[], joins[]);
`ReportFieldDefinition` (key, label, column [fully-aliased SQL fragment, e.g.
`t.task_date`], type [varchar|numeric|date|email|picklist], groupable,
aggregatable, picklistModule/picklistFieldKey, isCustomField/customFieldId)
with static constructors `::varchar()/::numeric()/::date()/::picklist()/
::userRef()`; `ReportJoinDefinition` supporting **direct FK**, **pivot**
(viaPivot/pivotLocalKey/pivotForeignKey — needed for Task↔Project), and
**polymorphic** (polymorphicTypeColumn/polymorphicIdColumn/
polymorphicTypeValue — needed for Campaign targets, Email links, and the new
CRM Activity's `related_type`/`related_id`) joins.

Custom fields: `fieldsFor()` queries `tblcustom_fields` for the module's base
table (tenant-scoped + `common_to_all`), appends each as
`isCustomField: true`, `type: 'varchar'` (display-only regardless of its real
declared type — no cast risk), `groupable/aggregatable: false`. At execution
time, custom-field columns are resolved via one extra
`g2g_custom_field_values` lookup keyed by the primary rows' ids, merged in
PHP — never joined.

### 2. Persistence (new migrations)

**`report_folders`**: id, sub_institute_id, module_scope
(`task_management`|`crm`), name, is_system (bool), owner_id (nullable — null
for system folders), sort_order, created_by/updated_by/deleted_by,
timestamps+softDeletes. No row ever represents "All Reports" or "Shared With
Me".

**`reports`**: id, sub_institute_id, module_scope, folder_id (nullable FK),
report_type (`tabular`|`summary`|`pivot`|`chart`), primary_module (registry
key), title, description, owner_id, sharingtype (`Private`|`Public`, default
Private), **definition (json)**, last_run_at,
created_by/updated_by/deleted_by, timestamps+softDeletes. Indexes:
`(sub_institute_id, module_scope, folder_id)`, `(owner_id)`, `(sharingtype)`.

`definition` JSON shape — **every field reference is a `{module, field}` pair,
never a single concatenated string**: module keys are themselves dotted
(`task_management.tasks`) and so are custom-field keys (`custom.
estimated_value`), so "split on the last dot" is ambiguous the moment a
custom field is involved. Discovered and fixed during Phase 0
implementation (`ReportModuleRegistry::resolve(moduleKey, fieldKey, ...)`
takes two explicit arguments, not one combined string):
```json
{
  "secondary_modules": ["task_management.projects"],
  "columns": [{"module": "task_management.tasks", "field": "status", "label": "Status", "visible": true, "aggregate": null}],
  "standard_filter": {"module": "task_management.tasks", "field": "task_date", "preset": "this_month", "from": null, "to": null},
  "advanced_filter": {"op": "AND", "conditions": [{"op": "OR", "conditions": [...]}, {"module": "...", "field": "...", "operator": "equals", "value": "..."}]},
  "sort_columns": [{"module": "...", "field": "...", "direction": "asc"}],
  "group_by": [{"module": "task_management.tasks", "field": "task_allocated_to"}],
  "pivot": {"rows": [{"module": "...", "field": "..."}], "columns": [{"module": "...", "field": "..."}], "values": [{"module": "...", "field": "id", "aggregate": "count", "label": "Tasks"}]},
  "chart": {"type": "VerticalBar", "group_by": {"module": "...", "field": "..."}, "data_fields": [{"module": "...", "field": "id", "aggregate": "count", "label": "Count"}]}
}
```
Server-side validation on every create/update (never trust the client
payload as execute-safe): every `field` string must resolve via the registry
for `primary_module`/`secondary_modules`; `chart.data_fields` capped at 3
(mirrors vtiger's own bar/line cap); `summary` requires non-empty `group_by`;
`pivot` requires `rows`/`columns`/`values` all present.

**`report_shares`** (modeled exactly on `document_library_shares`): id,
sub_institute_id, report_id (FK CASCADE), viewer_user_id (FK), can_edit
(bool), created_by, timestamps, unique(report_id, viewer_user_id).

**`crm_activities`** (new, built in Phase 2): id, sub_institute_id,
activity_type (Call|Meeting|Task, picklist-backed), subject, description,
related_type (nullable, polymorphic:
lead|organization|contact|opportunity|quote|ticket|...), related_id
(nullable), status (picklist-backed), priority (picklist-backed),
assigned_to (FK tbluser), due_date (indexed), duration_minutes,
created_by/updated_by/deleted_by, timestamps+softDeletes. Indexes:
`(sub_institute_id, related_type, related_id)`, `(assigned_to, due_date)`.
Seed `crm_picklist_values` rows for `module='activities'`
(activity_type/status/priority) following the exact pattern in
`2026_11_20_090600_create_crm_picklist_values_table.php`.

### 3. Query execution engine

`App\Domain\Reports\ReportQueryExecutionService::execute(object $report, int
$subInstituteId): array` — builds the base query from the registry (tenant
scope + soft-delete filter applied unconditionally from registry metadata,
**never** from the JSON — a report definition cannot express cross-tenant
access), applies joins (only registry-declared join paths, 3 fixed code
paths for direct/pivot/polymorphic — never user-supplied join SQL), applies
the standard date filter (presets resolved server-side via Carbon, never
trusting a client-sent range for a preset) and the advanced AND/OR filter
tree (recursive, each leaf's `field` resolved through the registry to a
trusted column, `operator` mapped from a fixed whitelist to parameterized
Builder calls), then shapes the result per type:

- **tabular** → `{rows, total, page, per_page}`, paginated, hard-capped at
  2,000 rows.
- **summary** → `{groups: [{label, count, ...aggregates}]}` — `GROUP BY` the
  group-by fields, `COUNT(*)` always included plus one `selectRaw` per
  aggregate column.
- **pivot** → one grouped SQL query over `(rows[0], columns[0])` (never a SQL
  `CROSSTAB` — MySQL has none), pivoted **in PHP** into `{row_labels,
  column_labels, matrix, row_totals, column_totals}`. v1 supports exactly one
  row dimension and one column dimension (matches vtiger).
- **chart** → summary-style grouped query, shaped as `{labels, series:
  [{name, data}]}` (≤3 series) — directly consumable by Recharts.

### 4. API surface

Prefix is `/api/report-builder/*`, **not** `/api/reports/*` — that prefix
already belongs to an unrelated, pre-existing HR/Talent analytics namespace
(`App\Http\Controllers\Reports\*`: kpi, hiring-analytics, employee
lifecycle, department distribution, ...), discovered via `route:list` during
Phase 0 implementation. No route actually conflicts, but two unrelated
"Reports" systems sharing one URL prefix is exactly the kind of thing that
confuses the next person reading the route list.

```
GET/POST/PUT/DELETE  /api/report-builder/folders[/{id}]
GET  /api/report-builder?scope={task_management|crm}&view={folder|all|shared}[&folder_id={id}]
POST/GET/PUT/DELETE  /api/report-builder[/{id}]
POST /api/report-builder/preview               # run an unsaved definition (builder live preview)
GET  /api/report-builder/{id}/run
GET  /api/report-builder/{id}/export?format=csv
GET/POST/DELETE  /api/report-builder/{id}/shares[/{shareId}]
GET  /api/report-builder/modules?scope={..}            # registry introspection for the builder
GET  /api/report-builder/modules/{moduleKey}/fields
```
`view=all` → every report in scope where `sharingtype='Public' OR
owner_id=me OR exists(report_shares WHERE viewer_user_id=me)`. `view=shared`
→ `sharingtype='Private' AND owner_id!=me AND exists(report_shares WHERE
viewer_user_id=me)` — directly mirrors `CalendarShareService::incoming()`.
`canEdit` = owner OR a share row with `can_edit=true` (same boolean shape as
`DocumentShareService::canEdit()`).

### 5. Frontend (g2gv0)

```
components/domain/reports/
  reports-shell.tsx          # FolderSidebar + ReportListTable + "New Report"
  builder/
    report-builder-wizard.tsx   # ModulePicker → ColumnPicker → FilterBuilder → TypeAndChartPicker → SaveDialog
  report-viewer.tsx          # switches on report_type
  tabular-view.tsx / summary-view.tsx / pivot-view.tsx / chart-view.tsx
lib/
  export-csv.ts              # NEW shared util (toCsv/downloadCsv) — no shared one exists today; cm-audit.tsx inlines its own, extract it properly
  report-chart-colors.ts     # NEW, sibling to lib/chart-colors.ts
components/ui/
  confirm-dialog.tsx         # promote the ConfirmDialog already in components/settings/sections/section-primitives.tsx to a true shared primitive here, rather than building a second one
```
Reused as-is: `components/ui/table.tsx`, `components/ui/searchable-select.tsx`,
`recharts` (chart patterns straight from
`components/domain/hrms/hrit/leave-management/leave-dashboard/components/{DepartmentChart,LineChart,LeaveTypeChart}.tsx`).
`ChartView` chart-type mapping: Pie→`<PieChart><Pie/></PieChart>` (single
data field only, matches vtiger), VerticalBar/HorizontalBar/Line→
`<BarChart>`/`<BarChart layout="vertical">`/`<LineChart>` with ≤3 series.

## Phasing

- [x] **Phase 0 (backend) — Engine foundation.** *(commit `c6268d84`, hp_erp)*
  - [x] Step 0: write this plan to `hp_erp/Docs/reports-engine/PLAN.md` and commit it.
  - [x] Migrations: `report_folders`, `reports` (longText `definition`, not json() — live's MariaDB predates JSON support), `report_shares`. Run on both `default` and `live`.
  - [x] Classes: `ReportModuleRegistry` (skeleton, empty `all()`), the 3 value objects, `ReportQueryExecutionService`, `ReportShareService`.
  - [x] Controllers/routes — prefix is `/api/report-builder/*`, NOT `/api/reports/*` (that's an unrelated pre-existing HR/Talent analytics namespace, found via `route:list`).
  - [x] Live-verified: empty-registry endpoints respond cleanly, unknown-module create returns a clean 422.
  - [ ] **Phase 0 (frontend, g2gv0) — still to do:** full shell/builder/viewer skeleton, `export-csv.ts`, `report-chart-colors.ts`, promoted `confirm-dialog.tsx` — no module content registered yet.

- [ ] **Phase 1 — Task Management (the proving ground).**
  - [ ] Register `task_management.tasks` (table `task`).
  - [ ] Register `task_management.events` (table `task_management_calendar_events`).
  - [ ] Register `task_management.projects` (joined via pivot `task_management_project_tasks`).
  - [ ] Seed 3 system folders: Task Reports, Events Report, Assigned Tasks Report.
  - [ ] Replace `tm-reports.tsx`'s menu entry (`content-map-m6.ts`, access_link `/module/task-management/reports-and-analysis`) with the new shell.
  - [ ] Live-verify: a real Task report (tabular), an Events summary grouped by status, an Assigned-Tasks chart grouped by assignee; confirm tenant scoping and CSV export.

- [ ] **Phase 2 — `crm_activities` (new entity).**
  - [ ] Build the `crm_activities` table + indexes.
  - [ ] Seed its `crm_picklist_values` rows (activity_type/status/priority).
  - [ ] Add a minimal activity log UI on CRM entity detail pages.

- [ ] **Phase 3 — CRM module registration (engine unchanged, reused as-is).**
  - [ ] Register all 15 CRM modules (leads, organizations, contacts, opportunities, quotes, sales_orders, purchase_orders, invoices, campaigns, tickets, products, vendors, service_contracts, assets, activities) with line-item/history children + polymorphic joins (campaign targets, activity↔related-record).
  - [ ] Seed the ~15 system folders matching vtiger's real default set and canned reports (recovered from `C:\Users\MILAN\Downloads\crm\modules\Reports\PopulateReports.php` / `languages/en_us/Reports.php` — see the exact list below).
  - [ ] "Triz Reports" seeds as an ordinary empty custom folder.
  - [ ] Add new top-level CRM menu leaf `/module/crm/reports` (new migration, parent = menu id 199, sibling to Marketing/Sales/Support) + matching `content-map-crm.ts` entry.
  - [ ] Leave `/module/crm/support/reports` (AI Stack screen) untouched.
  - [ ] Live-verify: at least one seeded report per folder returns real rows; "All Reports" aggregates correctly; "Shared With Me" only shows a report after an explicit share.

- [ ] **Phase 4 — Polish/stretch (explicitly lower priority, not silently dropped).**
  - [ ] Custom-field report columns beyond display-only.
  - [ ] Drill-down from a summary group to its member rows.
  - [ ] Scheduled/emailed report runs.
  - [ ] Caching expensive pivot/summary runs.

### Reference: vtiger's real default folders + canned reports (for Phase 3 seeding)

| Folder (our label) | Canned reports to reproduce |
|---|---|
| Organization and Contact Reports | Contacts by Organizations, Contacts without Organizations, Contacts by Opportunities |
| Lead Reports | Lead by Source, Lead Status Report |
| Opportunity Reports | Opportunity Pipeline, Closed Opportunities |
| Activity Reports | Last Month Activities, This Month Activities |
| Tickets Reports | Tickets by Products, Tickets by Priority, Open Tickets |
| Product Reports | Product Details, Products by Contacts |
| Quote Reports | Open Quotes, Quotes Detailed Report |
| Purchase Order Reports | PurchaseOrder by Contacts, PurchaseOrder Detailed Report |
| Sales Order Reports | SalesOrder Detailed Report |
| Invoice Reports | Invoice Detailed Report |
| Campaign Reports | Campaign Expectations and Actuals |
| Email Reports | Contacts Email Report, Organizations Email Report, Leads Email Report, Vendors Email Report (structure unconfirmed in legacy source — labels only; build from `crm_emails`+`crm_email_links` grouped by linked entity type) |
| Triz Reports | (none — ordinary empty custom folder) |
| All Reports | computed view, not a folder |
| Shared With Me | computed view, not a folder |

## Verification

- `php -l` every new/changed PHP file; `php artisan route:list` to confirm
  the new `/api/reports/*` routes register with no collisions.
- Migration run on **both** `default` and `live` connections per phase (not
  all at the end) — this session's established discipline, since `live`'s
  older MariaDB has repeatedly needed MariaDB-compatibility fixes other
  migrations didn't.
- Phase 1 live-verification: create a real Task report (tabular), an Events
  summary grouped by status, and an Assigned-Tasks chart grouped by assignee
  — confirm real data, confirm tenant scoping (a different tenant's token
  sees none of it), confirm CSV export.
- Phase 3 live-verification: spot-check at least one seeded report per
  folder actually returns real rows against live CRM data (not just that the
  screen renders), confirm "All Reports" aggregates across folders
  correctly, confirm "Shared With Me" only shows a report after an explicit
  share (and not before).
- `node node_modules/eslint/bin/eslint.js` + a full project `tsc --noEmit` on
  every new/changed frontend file (never `npx` — confirmed wrong version in
  this repo).
