# S2 — build contract (3 Nov 2026)

The shape every S2 track builds to. Written after the owner answered the six gate questions, so backend tracks and the front end can work at the same time. If a track finds this wrong, it says so in its report and does **not** silently change it. Source of the design: `S02-signals-front-door.md`.

## Owner decisions (3 Nov 2026)

1. New screen **Signal Review**, a separate menu leaf beside the old ones, Administrator only to start. The right to *decide* stays the existing `platformright:/signals,edit`.
2. `origin/main` merged into `Milan-2` first (done, commit `4fe2886b`); the two "live-only" migrations were on `origin/main` all along.
3. Reason list: the owner delegated it ("check by yourself"). Decided by the assistant: 9 reasons in `config('signals.review.reasons')` (see below).
4. Signals with no date stay visible with a "Date unknown" badge; the freshness facet defaults to **all**.
5. Scheduler: the owner left it to the assistant ("choose what users like"). Decided: **not switched on by this slice** (it spends search and AI quota unattended, and needs server cron + a queue worker, which only the owner can arrange). The Runs tab shows plainly "Runs are started by hand. No schedule is switched on." with last-run age, so the cost of turning it on later is visible.
6. The empty Signal Inbox stays exactly as is.

## Refinements to the slice file (decided while writing this contract)

- **Two entry points, one service.** The new screen calls a new strict endpoint `POST /api/signals/opportunities/{id}/decisions`. The original `PATCH /opportunities/{id}/{action}` stays, lenient, and writes through the same service. This is how "dismiss needs a reason" (new screen) and "the old buttons keep working" (no reason sent) both hold.
- **Column `via`** added to `g2g_signal_reviews` (`signal_review` | `legacy_screen` | `backfill` | `promote`), so history never pretends a backfilled or old-screen row carried a reason a person gave.
- **Fourth index** `g2g_opp_tenant_pm (sub_institute_id, product_master_id)` for the new product filter.
- **Name:** the existing class `App\Domain\Signals\Run\RunAccounting` counts stages (queries, pages, candidates). The cost class is therefore named **`RunCost`**, not `RunAccounting` as the slice file says.
- **`DateStatus`** (`app/Domain/Signals/Opportunities/DateStatus.php`, done) is the single rule for date status. Feed, backfill and anything that stores a signal use it.

## Statuses and words

Stored `review_status` values (unchanged): `New`, `Reviewed`, `Follow-up`, `Dismissed`. The screens call `Follow-up` "Contacted". History `decision` values: `reviewed`, `dismissed`, `follow_up`, `reopened`, `promoted`.

| Request decision | Stored `to_status` | `decision` | Event type |
|---|---|---|---|
| `reviewed` | `Reviewed` | `reviewed` | `signals.opportunity.reviewed` |
| `dismissed` | `Dismissed` | `dismissed` | `signals.opportunity.dismissed` |
| `follow_up` | `Follow-up` | `follow_up` | `signals.opportunity.contacted` |
| `reopened` | `New` | `reopened` | `signals.opportunity.reopened` |
| (promote to CRM) | unchanged | `promoted` | `signals.opportunity.promoted` |

Legacy route actions map: `review`→`reviewed`, `dismiss`→`dismissed`, `follow-up`→`follow_up`.

Reasons (config, code => label): `not_a_buyer` Not a buyer for our products · `already_customer` Already our customer · `wrong_product` Real signal, wrong product · `stale` Too old to act on · `duplicate` Duplicate of another signal · `source_unreliable` The source is unreliable · `out_of_region` Outside our region · `competitor_in_place` A competitor is already in place · `other` Other (a note is required).

## API (all under `/api/signals`, token + tenant from the token, as today)

### `POST /opportunities/{id}/decisions` — new, strict (the Signal Review screen)

Gate: `ModuleGate::signals('/signals', 'edit', 'read')` (same as the old buttons). Body:

```json
{ "decision": "dismissed", "reason_code": "stale", "note": "optional text", "expected_status": "New" }
```

- `decision` required, one of `reviewed|dismissed|follow_up|reopened`.
- `reason_code`: **required when `decision=dismissed`**, must be a key of `config('signals.review.reasons')`. If sent with any other decision it is ignored (stored NULL).
- `note`: optional, string, max `config('signals.review.note_max')` (1000) characters. **Required (non-blank after trim) when `reason_code=other`.**
- `expected_status`: optional. If sent and different from the signal's current `review_status` → 409 `conflict`.
- `reopened` is refused (409 `unchanged`) when the signal is already `New`.
- Same status as now and no note → 409 `unchanged`. Same status with a non-blank note is allowed (it records a note; one history row).

Success `200`: `{ "status": 1, "data": <presentOpportunity detail, same as GET /opportunities/{id}>, "review": <history entry> }`.
Errors: `404` `{status:0,message}` for a missing or other-tenant signal (no hint it exists); `422` `{status:0,message:"Validation failed",errors:{field:[msg]}}` field-keyed; `409` `{status:0, code:"unchanged"|"conflict", message, current: <latest history entry or null>}`; `403` from the gate.

### `PATCH /opportunities/{id}/{action}` — existing, lenient (the old screen)

Unchanged routes, unchanged response shape, unchanged tier. Now writes through `SignalReviewService` with `via=legacy_screen`. `dismiss` with no reason stores `reason_code = config('signals.review.legacy_dismiss_reason')` and, if no `notes`, the note `config('signals.review.legacy_note')`. If `reason_code`/`notes` are sent they are validated like the strict endpoint (unknown reason → 422; notes > max → 422). Same status and no note → **success, no history row, no event, nothing changed** (the old screen never errors on a double click). `review_notes` is overwritten only when a note is given (as today).

### `GET /opportunities/{id}/reviews` — history

Gate `view`. Newest first. `404` for other tenant.

```json
{ "status": 1, "data": [ {
  "id": 12, "decision": "dismissed", "reason_code": "stale", "reason_label": "Too old to act on",
  "note": null, "from_status": "New", "to_status": "Dismissed", "via": "signal_review",
  "decided_by": 3, "decided_by_name": "Milan Baldaniya", "decided_at": "2026-11-03T10:15:00+05:30"
} ] }
```
`reason_label` is null when `reason_code` is null or no longer in config (the code is still returned). `decided_by_name` is null when unknown. User names come from `tbluser` scoped to the tenant (`sub_institute_id`; never leak another tenant's user).

### `GET /opportunities` — existing; new optional query params and response keys

- `product_master_id` (integer), `date_status` (`event_date_stated|source_date_only|undated`), `freshness` (`last_30_days|last_90_days|older|undated`), and `sort=recent`.
- *Effective date* = `event_date`, else `source_published_at`, else none. `last_30_days`: effective date ≥ today−30d (a future date counts as fresh); `last_90_days`: ≥ today−90d; `older`: has an effective date and it is < today−90d; `undated`: no effective date. `sort=recent`: effective date descending, rows with none ordered by `first_discovered_at` and after the dated ones; ties by `id` desc.
- When none of the new params is sent the query, the order and the response are **identical to today's** (existing tests prove it).
- Each item gains: `date_info: {status, date}` (from `DateStatus::classify`, computed from the columns, not from the stored `date_status`), `product_master_id`, `product_master_name` (nullable; one batched lookup per page, tenant-scoped, shared rows `sub_institute_id=0` allowed).
- `filters` gains `review_reasons: [{value,label}]` and `date_statuses`.

### `POST /opportunities/{id}/promote-to-crm` — existing

Unchanged response. When a lead is **newly created** it also writes a history row (`decision=promoted`, `via=promote`, from=to=current status, note `Lead #<id> created`) and the `promoted` event, inside the same transaction. A repeat press (no new lead) writes nothing.

### `GET /research/runs`, `GET /research/runs/{id}` — existing

`presentRun` gains: `product_master_id`, `ai_input_tokens`, `ai_output_tokens`, `ai_cost_usd` (number or null), `search_requests`, `search_credits` (number or null), `duration_seconds` (null while running). Null means *unknown* and must stay null (never turned into 0).

## Events

Registered in `EventCatalogue::SHIPPED` with `AuditLogProjector`, as `crm.lead.created` is. Written by `SignalAudit::record($type, $tenantId, $opportunityId, $userId, $payload, $reviewId)` (pattern: `GtmAudit::record`; catches only the duplicate-key exception and returns the existing event). Idempotency key `{type}:{tenant}:{opportunityId}:{reviewId}`. Payload: `opportunity_id, review_id, decision, reason_code, from_status, to_status`. `EventCatalogue::assertInvariants()` must still return no errors. History row, signal update and event are **one DB transaction**: if the event write throws (anything but a duplicate key), nothing is saved.

## Cost capture

`RunContext` (static, like `RunBudget`): `set(runId, tenantId)` when a research run starts, `clear()` in a `finally`; `StructuredAi::json` passes `'meter' => ['module'=>…, 'institute'=>…, 'related_type'=>'g2g_research_run', 'related_id'=>runId]` when a run is active, and nothing extra when none is. `RunCost::fill(ResearchRun $run)` runs when a run finishes: `ai_input_tokens`, `ai_output_tokens`, `ai_cost_usd` = sums over that run's `ai_usage_events` (`related_type='g2g_research_run'`, `related_id`, same tenant) — **0 when the run used no AI**; `search_requests` from the run's own counters; `search_credits` stays NULL unless the provider reports credits in code that already exists.

## Backfill commands (each: dry run by default, `--apply` to write, `--tenant=`, `--database=`; one transaction per tenant; never overwrite a non-empty value; a re-run changes nothing; print counts)

- `signals:backfill-date-status` — `date_status` from `DateStatus::classify(event_date, source_published_at)`; also prints rows where the stored `assessment.evidence.date_status` disagrees (the JSON exists on only some rows). Skips sample rows (`is_sample`).
- `signals:backfill-reviews` — one `g2g_signal_reviews` row per signal whose `review_status` ≠ `New`, `via=backfill`, `reason_code` and `note` NULL (honestly unknown), `decision` from the status, `from_status=New`, `decided_by=reviewed_by`, `decided_at=reviewed_at` (skip a row with no `reviewed_at`? no: use `updated_at` and say so in the printed summary). Idempotent on `(opportunity_id, decided_at, decision)`.
- `signals:backfill-product` — `product_master_id` through `ProductResolver::forSignal` in the order `relevant_offer_id`, `product_key`, `business_fit`; unresolved stay NULL and are counted; prints counts per resolution path.

## Menu

Migration `2026_12_08_090300_create_signal_review_menu_row.php` (copy the S1 pattern, `2026_12_07_090200_create_product_master_menu_row.php`): leaf **Signal Review**, `access_link` **`/signal-review`**, parent = the existing Signals row (`/module/crm/signals`), granted to nobody. A separate command `signals:grant-review --tenant= [--apply] [--database=]` (copy `product-master:grant`) gives that tenant's Administrator profile(s) the **leaf only**, resolved through `RoleKey::fromProfile`, never an ancestor.

## Front end (g2gv0, branch `milan1`)

Route and content-map entries: `/signal-review` and `/module/crm/signal-review` (like `/signals` + `/module/crm/signals` in `hooks/content-map-crm.ts`). Built on the S1 scaffolds in `components/shared/scaffolds/` (ListPage, `useListQuery`, RecordDrawer, FormLayout, `useConfirm`); **`components/ui` is not edited**. Light-mode mock-up: `D:\dev\agentic-crm-research\published-pages\s2-mockup.html`. Product facet and names come from the Product Master `options` endpoint (S1). The old `ScholarSignalsView` is not changed.
