# Remove Prospecting and Forecast from GTM: what is there today (read-only, 26 Nov 2026)

Nothing was changed. No migration, no delete, no push. Every number below came from a SELECT or a search of the code, run today on dev (`mysql`, 202.47.117.220) and on live (`live` = db3, 128.199.17.97). Probe scripts: scratchpad `probe.php`, `probe2.php`.

## The two menu rows (verified, same on dev and live)

| id | name | access_link | status | parent | rights rows |
|---|---|---|---|---|---|
| 440 | Prospecting | /gtm/prospecting | 1 | 438 (GTM) | 15 |
| 464 | Forecast | /gtm/forecast | 1 | 438 (GTM) | 15 |

GTM group children today: Market Signals (476), Prospecting (440), Playbooks (442), Forecast (464) visible; Deals (444) and Products (468) status 0.

## Row counts (verified)

| table | dev | live (db3) |
|---|---|---|
| gtm_accounts | 7 | 124 |
| gtm_contacts | 10 | 170 |
| gtm_activities | 66 | 15 |
| gtm_deals | 2 | 3 |
| gtm_outreach_messages | 6 | 3 |
| gtm_stage_settings | 0 | 0 |
| gtm_forecast_snapshots | 1 | 0 |
| g2g_forecast_snapshots (the CRM forecast, NOT the GTM one) | 5 | 0 |
| gtm_renewals, gtm_health_snapshots | 0 | 0 |

## What makes "remove" more than deleting two screens (read in source)

1. `gtm_accounts` and `gtm_contacts` are not Prospecting's alone. About 60 app files read or write them: Deals, Outreach, Campaigns, Customers and Renewals, Workflows, Monitoring, GTM Reports, the Organization 360 page, consent facts, the OutreachGate, three Intelligence detectors, the AI report catalogue, the signal to lead conversion, and the S20 funnel built on 25 Nov (Signal -> Prospecting -> Lead; PRs hp_erp #603 and g2gv0 #395 not yet merged). About 35 PHP test files touch them.
2. Forecast: `gtm_forecast_snapshots` and `gtm_stage_settings` belong to the GTM Forecast screen. But `ForecastMath` is reused by the CRM's own `ForecastSnapshots` (docblock and use), `ForecastService` is called by `GtmScheduler` and `Gtm\Reports\ReportService`, and the front end mounts the same `ForecastView` on a second path, `/module/crm/sales/forecast` (`hooks/content-map-crm.ts` line 106). `g2g_forecast_snapshots` and `CrmForecastSnapshotController` are the CRM Sales forecast and must stay.
3. Menu and links that point at the two screens: `CrmGroupSidebarCommand` (GTM group list), three migrations that (re)create the rows (`2026_10_10_100100`, `2026_12_06_100000`, `2026_12_11_170000`), `lib/org-accounts/links.ts` (`gtm`), the signal card "Open in Prospecting", the GTM command center, the Deals screen link to Forecast.
4. DB-RULES say additive only and remove much later in its own slice; the owner's word for db3 overrides that, but live holds real rows (124 accounts, 170 contacts), so a before-image and a restore rehearsal come first.
5. Menu trap on record: a row with status 0 loses its rights on the next Role and Permissions save, so "hide" is not safe; deleting the row and its 15 rights rows is the honest removal.

## Tags

verified = SELECT run today. read in source = grep or file read today. inferred = the dependency reading in item 1 is by file name; the exact read/write per file is not yet traced (that is step 1 of the rewritten task).
