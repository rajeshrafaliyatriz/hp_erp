# Signals: AI signal sources (Claude, ChatGPT / GPT, DeepSeek, Gemini)

Where: **Signals → Ingestion Engine → Connectors → "AI signal sources"** (under the YouTube / LinkedIn / Facebook / Instagram connectors, same tab).

## What this is, and what it is not

Your scheduled tasks in Claude, ChatGPT, DeepSeek and Gemini already generate business-opportunity signals every day. **G2G imports their finished output. G2G never runs these models, never researches, never scrapes.**

**G2G cannot read a private chat history or a scheduled task's result from inside the vendor's app.** None of the four vendors gives a third party that access. The only thing that works is for the scheduled task itself to **send** its output to G2G as its last step (an HTTP POST), or for a person to **paste** the output into G2G. Both are built. Which one a given task can do depends on that task's platform: see "Per-platform reality" below.

```
scheduled task → its generated output → POST to G2G (or pasted by a person) → AI connector stores it as received
                → Sync imports it through the EXISTING market importer and quality gate → Signals list (Company Opportunities) → follow-up
```

## Using it in G2G

1. Open the connector card and click **Set up**. G2G creates the source and shows a **delivery secret once**. Copy it into the place your scheduled task keeps secrets. It is stored only as a hash and cannot be shown again; **Create a new secret** replaces it (the old one stops working at once).
2. **Delivery details** shows the address to POST to, the secret's last 4 characters, the recent deliveries and what became of each.
3. **Sync** imports what that source has waiting. **Sync All AI Signals** does it for every source that is switched on. A delivery also starts its own import the moment it arrives, so the daily feed needs no click.
4. **Switch off** makes the door refuse deliveries; **Or paste an output** accepts the JSON by hand for a task that cannot deliver by itself.

Imported signals appear in the normal Signals list, under the day they were delivered, with the badge **From Claude / GPT / DeepSeek / Gemini connector** and under the **Connectors** tab.

## The delivery

`POST /api/signals/ai-delivery` (the host of your G2G, for example `https://hp.triz.co.in`)

| Header | Value |
|---|---|
| `Authorization` | `Bearer g2gai_<id>_<48 hex>` (the secret from Set up) |
| `Content-Type` | `application/json` |

The secret identifies the connector, and the connector identifies your organisation. **Nothing in the body can name another organisation.**

### Body

```json
{
  "schema": "g2g.ai-signals.v1",
  "delivery_id": "claude-morning-2026-11-27",
  "provider": "claude",
  "task": "Scholar Clone - Morning Opportunity Feed (10:00 IST)",
  "generated_at": "2026-11-27T10:02:41+05:30",
  "signals": [ { "...": "one record each, see below" } ]
}
```

Only `signals` is needed. Also accepted: a bare list, `opportunities` / `records` / `items` instead of `signals`, a `data: { signals: [...] }` wrapper, and JSON wrapped in a Markdown code fence. `provider`, if sent, must match the connector (a Claude secret cannot deliver a delivery that says it is from Gemini). Limits: **200 signals and 2 MB per delivery** (`SIGNALS_AI_DELIVERY_MAX_RECORDS`, `SIGNALS_AI_DELIVERY_MAX_KB`); send the day's feed in several deliveries if it is bigger.

### A signal record

This is the existing market-import record (see `docs/signals-market-import-schema.md`); **the same gate applies, unchanged.**

```json
{
  "buyer": { "name": "Sunrise Public School", "type": "school", "state": "Maharashtra", "website": "https://www.sunriseschool.in" },
  "trigger_type": "vendor_search",
  "trigger_summary": "The school has invited school-ERP vendors to demonstrate for the 2027-28 academic year.",
  "what_happened": "Notice dated 24 Nov 2026 on the school website invites ERP vendors to register for demonstrations by 10 Dec.",
  "source_url": "https://www.sunriseschool.in/notices/erp-demonstration",
  "source_title": "ERP vendor demonstration notice",
  "event_date": "2026-11-24",
  "claim_level": "confirmed",
  "fetch_level": "page_text",
  "likely_stakeholder": "Principal",
  "business_fit": "scholar"
}
```

**Required by the gate (a record without one is rejected, with the reason, never silently fixed):** `buyer.name`, `trigger_summary`, `source_url` (a real http(s) link), a date (`event_date` or `observed_at`, ISO `YYYY-MM-DD`), `claim_level` (`confirmed` / `inference` / `hypothesis`) and `fetch_level` (`full_document` / `page_text` / `search_snippet` / `blocked`).

Evidence rules still hold: a `search_snippet` or `blocked` source can never be `confirmed`, it is stored as `inference`. Nothing is invented: if the task did not send a date, the record is rejected as `missing_date`; a date that is not ISO is rejected as `invalid_date`.

**Tolerated field names** (moved into the canonical name only when the canonical one is absent): `company` / `organisation` / `account` / `institution` / `school` → `buyer.name`; `event` / `signal` / `summary` / `headline` / `title` → `trigger_summary`; `url` / `link` → `source_url`; `date` / `published_at` → `event_date` (the value must still be ISO); `evidence` / `quote` → `what_happened`; `signal_type` → `trigger_type`. Values such as `Confirmed` or `Search Snippet` are lower-cased. The original text is always kept as received.

### Contacts that came with the signal (optional)

A record may carry a `contacts` list (at most 10). Each is stored for the signal's organisation exactly like the contacts the public-contact lookup finds: tenant-scoped, **unverified**, shown on the signal with the page each came from.

```json
"contacts": [
  {"full_name": "Asha Rao", "designation": "Chief Executive Officer", "source_url": "https://example.org/leadership", "linkedin_url": "https://www.linkedin.com/in/asha-rao"},
  {"designation": "Organisation contact (published)", "phone": "+91 22 1234 5678", "email": "info@company.in", "location": "Address as printed", "source_url": "https://company.in/contact"}
]
```

Rules, so nothing is made up here: a contact with **no http(s) `source_url`** (the page that printed it) is dropped; an email or phone is kept only with a source page and must pass the same checks as everywhere else (an Indian landline needs its `+91` or leading `0` and STD code; `example`, `test`, `noreply` addresses are refused); `linkedin_url` must be a linkedin.com address; a bad contact never fails the signal.

### Dates must be ISO

`event_date` is **`YYYY-MM-DD`** (for example `2026-11-24`). `observed_at` may also carry a time (`2026-11-24T10:00:00+05:30`). `expires_at` / `closing_date` / `deadline` are `YYYY-MM-DD`. **Anything else is rejected with a reason**, never guessed: `last Tuesday`, `yesterday`, `24/11/2026`, `Nov 24`, `2026-13-45`. G2G would otherwise work a phrase like "last Tuesday" out from the day it **imports** the signal, which gives a wrong event date. (This strict rule applies to AI deliveries only; the other imports are unchanged.)

### Where a delivered signal appears (ICP type, region, product)

The Signals screen has ICP-type, region and product selectors. G2G fills them **only from what the task explicitly sent**, using the product catalogue's own definitions, and only into fields that are still empty:

| You send | G2G stores |
|---|---|
| `buyer.type` = `government` or `public_sector` | ICP type **B2G** |
| `buyer.type` = `enterprise`, `sme`, `school`, `school_group`, `college`, `university`, `institutional` | ICP type **B2B** (the catalogue lists School, College, University, Institution and Enterprise as B2B buyers). **A government-run school must be sent as `government` or `public_sector`.** |
| any other `buyer.type` (`hospital`, `training_institute`, `other`) or none | **no ICP type** |
| `buyer.state` (and optionally `buyer.country`) | a region, **only if the location catalogue verifies it**. A state alone resolves to its country only when exactly one country has a state of that name (`Maharashtra` → India; `Punjab` is ambiguous and gives no region). A state that is not in the stated country is dropped; the stated country is kept. |
| `business_fit` | the product, when exactly one of your products has that business fit |

**Stating the ICP type outright:** add `"customer_model"` (or `"icp_type"`) to the record: `b2b` (B2B Customer Acquisition), `b2c` or `b2b_partner` (**B2B Partner Acquisition**: resellers, integrators, distributors, agencies and delivery partners who sell or deliver the product for their clients), or `b2g` (government). What a record states wins over what its `buyer.type` implies; a value that is none of these is ignored and the buyer type decides, as before. It is written only into an empty column, like the rest of the classification.

A signal with **no ICP type or region is not guessed into any**. It matches no B2B / B2C / B2G or region selector. It is always found under **All ICP types** (with no region chosen), and its card says "ICP type not stated · see All ICP types".

**Expected result for the two samples** (verified in tests):

| View | Documentation school sample (`school`, Maharashtra) | Government sample (`government`, Delhi) |
|---|---|---|
| B2G + India (the view in your screenshot) | **not shown**: it is a B2B buyer | **shown** |
| B2B + India | shown | not shown |
| B2B + Maharashtra / B2G + Delhi | shown | shown |
| All ICP types + India, or + All regions | shown | shown |

Government sample: `{"buyer":{"name":"Delhi Directorate of Education","type":"government","state":"Delhi"},"trigger_type":"tender","reference_no":"DoE/ERP/2026/41","trigger_summary":"The Directorate has floated a tender for a school management system.","source_url":"https://edudel.example.gov.in/tenders/erp-2026","event_date":"2026-11-24","expires_at":"2026-12-20","claim_level":"confirmed","fetch_level":"page_text","business_fit":"g2g"}`

### Why a signal is rejected, and where you see it

Every signal the quality gate rejects is listed under **Recent deliveries** on its card with the organisation, the reason and **how to fix the task**; a delivery shows *new, updated, duplicate, rejected and pending*. The most common reasons: a missing `buyer.name`, `trigger_summary`, `source_url` or date; a date that is not ISO; an invalid `claim_level` / `fetch_level`; and **free text in an optional field that only accepts listed values** (`buyer.type`, `trigger_type`, `business_fit`, `scale`, `entry_point`, `candidate_need_codes`): use exactly a listed value or leave the field out.

### Interrupted imports

If a worker dies while importing, the delivery stays `importing`. After **30 minutes** without progress (`SIGNALS_AI_DELIVERY_STUCK_MINUTES`) the next sync imports it again, up to 3 tries, then marks it failed with the reason. Re-importing is safe: signals already stored are recognised and counted as duplicates, so nothing is created twice.

### Look-alike organisations

G2G links a delivery to an existing organisation only by **exact normalised name** or an alias the organisation already has. "Sunrise Public School" and "Sunrise Public School, Pune" stay **two organisations**, because a shared word list or website does not prove they are the same (a school group shares one site, chains share names). A close match is shown in Recent deliveries as "Possible same organisation, not merged" so a person can decide. Use one spelling per organisation in the task. An `aliases` list in a delivery does not merge anything.

### Answers

| HTTP | Meaning |
|---|---|
| 202 | Stored. The import has started (or will be picked up by the import already running). `data.delivery_id` identifies it. |
| 200 `duplicate: true` | This exact content was already received (spacing and key order do not matter). Nothing is stored twice. |
| 401 | Unknown connector, wrong secret or no secret: all the same answer. |
| 403 | The connector is switched off in G2G. |
| 413 / 422 | Too large / too many signals / not JSON / no list of signals / provider mismatch. A body that is not readable is kept as a failed delivery so the failure is visible on the card. |
| 503 | Delivery is switched off or not migrated on this server. |

### Duplicates and counts

- **A delivery repeated** is recognised by its content and stored once.
- **A signal repeated** (in the same delivery or a later one) is recognised by the existing importer (same buyer, same trigger, same reference or link) and counted as a **duplicate**. If the later copy has advanced (a later date, stronger evidence) it is **updated**, not duplicated.
- The card shows, per source: last received, last sync, and totals for **imported** (new + updated), **duplicates**, **failed** (signals the gate rejected + deliveries that could not be read) and **waiting**. A delivery that errors is retried by the next sync up to 3 times; a signal the gate rejects is a verdict on the data and is not retried.

## Claude: Scholar Clone — Morning Opportunity Feed (10:00 IST)

This is the first integration. **G2G does not change the task's logic.** What the task needs is one extra, final step. **Adding it is a change to your scheduled task, which only you can authorise.** Suggested wording for that final step:

> After you have produced today's opportunity feed, write the final result as one JSON object in the format of `g2g.ai-signals.v1` (a `signals` list; each signal with `buyer.name`, `trigger_type`, `trigger_summary`, `what_happened`, `source_url`, `event_date`, `claim_level` and `fetch_level`; use `claim_level: "confirmed"` only for something you read on the source page, and say `search_snippet` as the `fetch_level` if you only saw a search snippet; do not invent a value you do not have, leave the signal out instead). Set `delivery_id` to `claude-morning-` followed by today's date. Save it as `feed.json` and run:
>
> `curl -sS -X POST "$G2G_DELIVERY_URL" -H "Authorization: Bearer $G2G_AI_SECRET" -H "Content-Type: application/json" --data-binary @feed.json`
>
> Report the answer you get back. A `202` or a `200` with `duplicate: true` means it was delivered.

Things only you can check, because they depend on where the task runs:

- **The task must be able to make an outbound HTTPS request** to your G2G host and run `curl` (or an equivalent HTTP tool). Some scheduled environments block outbound network access or restrict it to listed domains: allow your G2G domain.
- **Keep the secret out of the prompt text.** Put it in whatever the task offers for secrets or environment variables (`G2G_AI_SECRET`, `G2G_DELIVERY_URL` above are only names).
- If the task **cannot** make an HTTP call, use **Or paste an output** on the card each morning: same checks, same import.

### Sample for a quick test (replace host and secret)

```bash
curl -sS -X POST "https://YOUR-G2G-HOST/api/signals/ai-delivery" \
  -H "Authorization: Bearer g2gai_<id>_<secret>" \
  -H "Content-Type: application/json" \
  -d '{"delivery_id":"claude-test-1","signals":[{"buyer":{"name":"Sunrise Public School","type":"school"},"trigger_type":"vendor_search","trigger_summary":"The school invited ERP vendors to demonstrate.","source_url":"https://example.org/notice","event_date":"2026-11-24","claim_level":"inference","fetch_level":"search_snippet"}]}'
```

(Do not run this against a live organisation with a made-up school: the signal would be stored. Use a real feed, or your test organisation.)

## Per-platform reality: what remains for GPT, DeepSeek and Gemini

The receiving side is the same for all four and is built. **What is not built, and cannot be built from G2G, is the sending side**, because it lives in each vendor's product:

| Source | Can its scheduled task deliver by itself? | What you need |
|---|---|---|
| **ChatGPT / GPT** | ChatGPT *Tasks* run inside the app and cannot call an arbitrary URL. | Either run the same prompt through the **OpenAI API** from a scheduler you control (a cron job, a cloud function, Zapier/Make) and `POST` its output to G2G; or use **Or paste an output**. A Custom GPT *Action* can POST, but only when a person is chatting with it, not on a schedule. |
| **DeepSeek** | The DeepSeek chat app has no scheduling or webhooks. | Run the prompt through the **DeepSeek API** from your own scheduler and `POST` the result; or paste it. |
| **Gemini** | Gemini *scheduled actions* in the app cannot call an arbitrary URL. | Run the prompt through the **Gemini API** (for example from Google Apps Script or Cloud Scheduler) and `POST` the result; or paste it. |

For each you also need: the task's output in the `g2g.ai-signals.v1` format (add that format to the task's prompt), a secret from **Set up** on that card, and your explicit decision to change that scheduled workflow.

## Security

- The secret is shown once, stored only as a SHA-256 hash inside the connector's encrypted credentials, compared in constant time, never logged and never returned by any API. The delivered text is not logged either.
- The endpoint is public but answers every failure with the same `401`, so it cannot be used to discover connectors. It is rate-limited.
- The organisation always comes from the connector, never from the body. Another organisation's connector, deliveries and signals are invisible and unreachable.
- Delivery does not call any model, search provider or scraper. Signals only enter through the existing quality gate.

## Deploying (needs the owner's go; nothing here has been run on any database)

One new table, additive and idempotent, written for MariaDB 10.1:

```
php artisan migrate --path=database/migrations/2026_12_29_100000_create_g2g_ai_deliveries_table.php
```

Then `php artisan config:clear` and `php artisan queue:restart`. A **Signals queue worker** must be running: a delivery's import runs on the `signals` queue exactly like a connector sync. Switch the whole feature off with `SIGNALS_AI_DELIVERY=false`.

Note: every newly imported signal triggers the public-contact lookup like any other new signal, which spends search credits. Set `SIGNALS_CONTACT_RESEARCH=false` if you do not want that for a large daily feed.
