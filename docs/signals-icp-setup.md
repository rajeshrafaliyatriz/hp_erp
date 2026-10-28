# Signals: each organisation's own ICP

An ICP (ideal customer profile) is **data an organisation owns**, not code. It says which organisations count, what to
ignore, which events to look for, what to search in the buyer's words, and how long a signal stays fresh. An
organisation can have several (one per product). Only an **active** ICP is researched.

All routes below are administrator-only, scoped to the caller's organisation, and live under `/api/signals/icp`.

| Do this | Call |
|---|---|
| See the templates to start from | `GET /templates` |
| Create an ICP from a template | `POST /profiles` `{ "key": "scholar-k12", "name": "Scholar K-12", "template": "scholar_k12" }` |
| Create an ICP from your own settings | `POST /profiles` `{ "key": "hospitals", "name": "Hospitals", "config": { ... } }` |
| Check settings without saving | `POST /validate` `{ "config": { ... } }` |
| Edit (every change keeps the old version) | `PUT /profiles/{id}` `{ "config": { ... }, "note": "why" }` |
| See what today's run would search | `GET /profiles/{id}/preview` (calls no search or AI provider) |
| Activate, archive, set the default | `PATCH /profiles/{id}/status` `{ "status": "active", "is_default": true }` |
| See the organisations it watches | `GET /profiles/{id}/accounts` |

A new ICP starts as a **draft**. Activate it when the preview looks right. An invalid ICP cannot be saved or activated;
the response lists every problem.

## What an ICP contains (the same shape for any industry)

| Setting | Meaning |
|---|---|
| `label`, `industry_label`, `product_line` | What you sell and to whom |
| `regions`, `buyer_types`, `parts` | Where, who, and the parts of what you sell |
| `must_have` | Hard rules. Each is checked **only when its fact is known**: an unknown fact never rejects an account |
| `watch_rules` | Keep on **watch**, not reject, while a fact holds (for example `within_days` of a date: a new ERP signed in the last 12 months) |
| `vendor_phrases`, `vendor_news_phrases` | Who **sells** what you sell (competitor or partner, never a buyer): words in the name or sector, and wording only a seller's news has. They land in the context section whatever the model said |
| `disqualifiers` | Phrase lists matched against the organisation name. Plain words only, never patterns |
| `manual_flags` | Disqualifiers a person sets (nobody can read them off a web page) |
| `criteria` | Facts about an account that earn points (stored on the account) |
| `evidence_points`, `evidence_rules` | Judgement and live-evidence points added when a lead is reviewed; `evidence_rules` gives the points for the two that are **counted** (triggers, parts with a pain) |
| `priority`, `priority_cities` | Score bands (P1, P2, P3; below the last is not pursued), and cities to watch first |
| `tiers` | How you enter an account (A, B, C). A person judges it; it is never computed |
| `triggers` | Events to look for, where they appear, and how long they stay fresh |
| `queries` | Searches in the **buyer's** words (never your product words). News, job posts, tenders, official sites |
| `per_run`, `account_query` | How many searches a run uses, and the per-organisation search |
| `buying_centre` | Roles to approach and what each one cares about |

## Accounts (the organisations an ICP watches)

```
php artisan signals:import-accounts list.csv --tenant=9 --profile=hospitals --preset=generic \
     --map="name=Hospital;city=City;state=State;attr.beds=Beds"      # any CSV; add --dry-run first
php artisan signals:import-account-facts facts.csv --tenant=9 --profile=hospitals   # add facts later, any columns
```

A Vtiger "Leads" export works with the default `--preset=vtiger`. Contact phone numbers and emails are never imported;
`crm_ref` is the CRM lead number so a person can find the contact there.

## Safety built in

- **Tenant isolation:** another organisation's ICP is answered exactly like a missing one (404).
- **No raw patterns:** a disqualifier is a phrase list, so nobody can write a pattern that freezes the server.
- **Bounds:** sizes, counts, vocabularies and date windows are checked; unknown settings are refused.
- **Version history:** every saved change keeps the previous configuration.
- **Never searches on a draft:** and a run for a chosen ICP is refused unless that ICP is active and belongs to the caller.

## Will research actually run? (`signals:diagnose`)

```
php artisan signals:diagnose --tenant=6      # steps, then the overview for that organisation
php artisan signals:diagnose --all           # the overview of every organisation with a product profile or an ICP
```

The overview shows, per organisation: its active ICPs, whether the daily schedule is on, the next run time, whether the
scheduler has ticked, whether a search provider and an AI provider are configured (**yes or no only, never a key**), and the
last run. An organisation whose ICPs exist but none is active gets **no** scheduled run (it never silently falls back to generic
research).

`php artisan signals:icp-seed --tenant=6 --template=scholar_k12 --activate --default --enable-schedule` copies a template,
activates it, and switches the daily schedule on (only when the product profile is complete).

## Before a schema change or an import

`php artisan signals:backup-tables` writes every Signals table and `migrations` to `storage/app/backups/` as `.sql` files and
checks each table's row count. It is read-only.

## One event is one opportunity

The importer treats a record as the **same event** when it has the same buyer and trigger type and an event date within 7 days
(or the same tender reference). A new URL for that event adds a source, records a `corroborated` event, and may raise the evidence
level; it never creates a second opportunity and never touches review or pipeline status.

## How a school's score is built

- **Facts you add** (students, grades, fee, management, campuses, board): `php artisan signals:import-account-facts facts.csv --tenant=6 --profile=scholar_k12`. The columns are `crm_ref` (or `name` and `city`) plus any of `students, grades_up_to, fee_inr, management, campuses, board, erp_signed_on, tier, flags`. Until these are filled, most schools stay "unscored".
- **Evidence counted by the system:** every time a lead is saved for a school, `AccountEvidence` recounts the school's own signals from the last 90 days: how many different triggers fired and how many parts of the bundle they point at. The result is stored in the account's `icp_notes.evidence` (points, triggers, parts, total, priority).
- **Evidence judged by a person:** set `attributes.evidence.leadership_willingness` and `financial_capacity` on the account (points, never above the ICP's maximum).
- **Watch:** a school kept on watch (for example a new ERP signed in the last 12 months) can still be tracked, but a lead for it is placed on the watch list, never in "act now". The move is recorded as a `capped_by_watch` event.
