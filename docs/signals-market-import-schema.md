# Signals: market (demand-side) import

Feeds the **Company opportunities** pipeline with structured demand-side records (the daily scan).
One service, three entry points, identical behaviour:

| Entry point | Use |
|---|---|
| `POST /api/signals/market/import` | The daily agent / the UI. Admin or HR token. Tenant comes from the token. |
| `php artisan signals:import-market <file> --tenant=<id>` | By hand, locally or on the server. `--dry-run` validates only. |
| `MarketImporter::import()` | Tests, other code. |

Related reads (admin/HR): `GET /api/signals/market/scan-log`, `GET /api/signals/market/rejections?scan_log_id=`.

## Request

JSON body: `{"source_label": "daily-agent", "records": [ ... ]}` or a bare array.
Multipart: `file` (`.json` or `.csv`), optional `source_label`, `dry_run=1`, `ingestion_source_id`.
Limits: 500 records and 5 MB per request (`SIGNALS_MARKET_IMPORT_MAX_RECORDS`, `SIGNALS_MARKET_IMPORT_MAX_KB`).
**XLSX is not read in Phase 1**: save the sheet as CSV. CSV headers are the field names below with the
nested parts flattened: `buyer_name`, `buyer_type`, `buyer_state`, `buyer_aliases` (use `|` between aliases),
`buyer_website`, `score_buying_signal` ... `score_evidence_quality`, `score_reasoning`; list cells such as
`candidate_need_codes` use `|`.

## Record

```json
{
  "buyer": { "name": "string (required)", "type": "government|institutional|sme|school|enterprise|other", "state": "string?", "aliases": ["string?"], "website": "url?" },
  "trigger_type": "tender|rfp|eoi|rfq|programme|regulation|expansion|funding|acquisition|leadership|hiring|partnership|other",
  "trigger_summary": "string (required): the specific event that could cause a purchase",
  "what_happened": "string: confirmed fact only",
  "source_url": "http(s) url (required)",
  "source_title": "string?",
  "reference_no": "string?  (tender no / circular no)",
  "event_date": "YYYY-MM-DD   (required unless observed_at is given)",
  "observed_at": "ISO datetime",
  "expires_at": "YYYY-MM-DD?  (or closing_date / deadline for tender, rfp, eoi, rfq)",
  "claim_level": "confirmed|inference|hypothesis   (required)",
  "fetch_level": "full_document|page_text|search_snippet|blocked   (required)",
  "business_fit": "eb|scholar|g2g|multiple",
  "candidate_need_codes": ["N01"],
  "buyer_segment": "string?",
  "likely_problem": "string?  (never invented)",
  "likely_stakeholder": "string?  (only if the source supports it)",
  "what_we_could_sell": "string",
  "entry_point": "discovery_meeting|pilot|poc|paid_assessment|workshop|si_partnership|rfp_response|other",
  "scale": "small|medium|large|strategic",
  "estimated_value": "string?  (only if the evidence supports it)",
  "is_government_track": false,
  "partner_route_note": "string?",
  "soft_marketing_angle": "string?",
  "scores": { "buying_signal": 1, "problem_fit": 1, "product_fit": 1, "accessibility": 1, "urgency": 1, "potential_value": 1, "evidence_quality": 1, "reasoning": "string" }
}
```

## Gates (per record; each yields exactly one outcome)

| Outcome | When |
|---|---|
| **rejected** | No buyer, no `trigger_summary`, no date, a missing/invalid `source_url`, an invalid enum, a need code outside N01-N20, or `scores` that are not all seven integers 1-5. Stored with its payload in `g2g_signal_import_rejections`. |
| **accepted** | New signal stored (`review_status=New`, `pipeline_status=NEW`). |
| **updated** | Same signal (same buyer + trigger type + reference number or URL) and it **advanced**: later `event_date`, a moved `expires_at`, a stronger `claim_level` or `fetch_level`. Writes a row to `g2g_opportunity_events`. Never changes `review_status` / `pipeline_status`. |
| **duplicate** | Same signal, nothing new. Never re-flagged. |

* **Evidence grade.** `search_snippet` or `blocked` can never be `confirmed`: it is stored as `inference`, with the reason in `evidence_note` and an `evidence_downgraded` event.
* **Expiry.** `expires_at`, else the closing date of a tender/RFP/EOI/RFQ, else none ("no expiry").
* **Government track.** A government buyer with no state whose text names CBP / CBC / Mission Karmayogi / iGOT is flagged `is_government_track` and its `entry_point` defaults to `si_partnership`. State departments and institutions are never auto-flagged. `is_government_track: true` is always honoured.
* **Buyer resolution.** Exact match on the normalised name, then on aliases. A near-match is only *reported* in the scan log (`buyer_suggestions`), never merged.
* **Scores** are stored exactly as sent (total out of 35); nothing is invented. `priority` is derived: total >= 28 High, >= 20 Medium, else Low (Low when unscored).
* **Samples.** Records with `"is_sample": true` are stored with `is_sample=1` so feeds can exclude them.

## Response

```json
{ "status": 1, "data": { "scan_log_id": 12, "received": 40, "accepted": 31, "updated": 2, "duplicate": 5, "rejected": 2,
  "results": [ { "index": 0, "outcome": "accepted", "id": 101, "buyer_id": 7, "buyer_created": true },
               { "index": 1, "outcome": "rejected", "reason_code": "missing_date", "reason": "..." } ] } }
```
