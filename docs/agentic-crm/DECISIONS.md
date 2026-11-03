# Agentic CRM — Decisions

Owner decisions and the reasons behind plan choices. Newest first within each section. Every entry has a date and who decided.

## Owner decisions

### 2026-11-03 (late evening and 20:00) — After seeing the real sidebar (owner: Milan Baldaniya)

| Instruction | Answer | What it means |
|---|---|---|
| Duplicate screens | "we already have the product sub module, why you make new one Product Master, you should update it"; "we already have the organization on the side bar"; "work like a specialist, do not create anything blindly" | The Product Master (menu 505) and Organisation 360 (menu 507) entries are removed (rolled back on dev); both are folded INTO the existing Products and Organizations screens. Their data layers stay as plumbing. Rule recorded in `SESSION-RULES.md`. |
| Visible agent | "not any agentic feature is still seeing, I like Comp AI, I do not want to compromise anything, I want the G2G agentic CRM" | The Organisation agent (thin S7) is built now, visible on the Organization detail page and in the existing Agent Run History screen. See the plan-change row below. |
| Ingestion Engine and Partner Network | "please do not remove the ingestion engine and partner network features in the signals" | Protected (row below). Nothing in this effort touches them. |
| Signals design | "do not change it yet ... the 4 tabs should be as it is in the same design ... we will change it at last if needed; what you can change inside it is the business logic" | The Signals screen design is frozen (row below). Product Master logic may feed Product Portfolio's business logic. |
| Re-read first | "do not start the work blindly, first re-read all our work, documents, artifacts, plan, then start" | Done at the start of the 20:00 session: `00-PROGRESS.md`, `DECISIONS.md`, `SESSION-RULES.md`, `PLAN.md`, the published artifact, the git and database state; the cut-off agents' transcripts were located in the session's `subagents` folder. |
| The plan | "we are not cutting any planning work; if any change is needed, change it, with the reason" | No slice is cut. Changes to the plan are written in `PLAN.md` ("Changes since approval") and here, each with its reason. |
| Pace | "work parallel and smartly" | Three tracks at once, each with a written brief (`briefs/`), sized to the usage limit. |

### 2026-11-03 (night) — Standing instructions for the rest of the build (owner: Milan Baldaniya)

| Instruction | Answer | What it means |
|---|---|---|
| Decide yourself; 360-degree; as a specialist, not a generalist | "take decision by your self … should be 360 degree view based and as specialist not generalist" | Every design decision is written as a record with six lenses (user, data, security and legal, operations and cost, reversibility, time) and a door (one-way or two-way), from raw evidence; only one-way, outward-facing decisions come to the owner. Slice 3 is the first built this way: `slices/S03-organisation-360.md`. |
| Pushing | "currently do not push, we will push at last" | Nothing is pushed until the owner says; the two refused pushes are therefore not pending any more. Commits stay local on `Milan-2` and `milan1`. |
| Pace and quality | "we complete only 20% … half day is completed … do not cut the plan work and reduce the quality" | Speed comes from removing waiting (parallel tracks sized to the usage limit, small saved increments, a fast test tier per change, the full suite once per slice), never from cutting scope or gates. |
| Template (deeper, not bigger) | the owner's four questions before any rewrite | Answered for each new instruction: what a top professional would add, the rawest material, the methods and tools, and what other than the assistant or the owner would show the result is right. |

**Slice 3 decisions made by the assistant (full records in the slice file):** D1 a thin identity table with loose pointers (two-way); D2 auto-link only on exact name or domain, unambiguous, place-guarded, everything fuzzy is a human suggestion (measured: exact name 6 of 6 on live, domain alone 4 of 6, fuzzy adds nothing); D3 lifecycle set explicitly; D4 create the CRM organisation from the identity, restore a deleted same-name one on request, no change to the unique index; D5 widen the four CRM email columns to 191; D6 fix the organisation merge from one registry with a scan test and a property test; D7 a read-only capped 360 aggregate; D8 new menu leaf, Administrator first; D9 the backfill never writes to the three source tables.

### 2026-11-03 (evening) — Answers after S2 was built (owner: Milan Baldaniya)

| Question | Answer | What it means |
|---|---|---|
| Review the Signal Review screen now | "i will check all the work after complete our agentic crm system enterprise level" | No review gate is waiting on the owner for S1 or S2. Each screen is still built to its approved mock-up and checked in a real browser; the owner reviews everything at the end. Design gates for new slices still apply. |
| The nine dismiss reasons and "scheduler stays off" | Asked what the scheduler means and whether GTM agents use the Comp AI architecture: "if GTM agents use the same architecture, set it" | The reasons stand (no objection). The scheduler is not an agent: it is the server clock. GTM agents are button-only today (`'trigger' => 'manual'`), so the shared architecture does not exist yet. Decision: signal research becomes the first scheduled agent in S7 (gateway, autonomy, budget) and S12 (triggers), not a one-off timer. **Fact found: on live nothing runs on a clock at all** (2 task-log rows ever, 28 Sep). Steps for the server administrator: `OPS-SCHEDULER.md`. Switched on nowhere. |
| Who supplies AI prices; currency | "default money is rupees, the organisation will supply, and the developer company; choose the option yourself, do not show laziness" | A currency-aware rate card (default INR): the developer company fills platform default rows (a command), an organisation fills its own row in the model manager (per million tokens), priced when each call is made; runs show ₹. Default for `deepseek-flash`: ₹28.80 / ₹115.20 per million tokens (DeepSeek's official peak price at about ₹96 per dollar; an estimate). Contract: `slices/S02-pricing-contract.md`. |
| Live rollout of S2 | "yes roll out i give you the permissions" | Database part done on live the same evening (`_evidence/s2-live-rollout-2026-11-03.md`). The code deployment is not something the assistant can run (no pipeline, and the push to the remote was refused by the tool's safety check): the owner pushes and deploys; the grant follows the deployment. Slice 1 is a separate rollout. |
| The mock-up gaps | "also feel gaps solve it" | Built: Turn into a lead, Fit and score tab, Generate signals, Export, a 403 browser check. |
| The goal | "build full working from frontend, backend, database; users interact with the frontend; a full enterprise-level agentic CRM that manages the company's sales" | The 15-slice plan stands; each slice ends with a working screen on all three layers, checked in a browser. |

### 2026-11-03 — Answers to the six S2 gate questions (owner: Milan Baldaniya)

| Question | Answer | What it means |
|---|---|---|
| 1. Separate Signal Review line beside the old one, admin-only to start | "yes" | Menu leaf `/signal-review` under the existing Signals branch; granted to the Administrator profile only; the old Company Opportunities screen is untouched. The right to decide stays `platformright:/signals,edit`. |
| 2. Merge `origin/main` first; who finds the two live-only migrations | "pull from the main branch first then start work" | Done: merge commit `4fe2886b`. The two migration files were on `origin/main` all along. |
| 3. Is the dismiss-reason list right | "i have no idea, please check by yourself and decide" (assistant decides) | Nine reasons in `config('signals.review.reasons')`: not_a_buyer, already_customer, wrong_product, stale, duplicate, source_unreliable, out_of_region, competitor_in_place, other. `no_budget` dropped (a buyer's budget is learned after contact: a lead outcome, not a reason a public signal can be judged on); `out_of_region` and `competitor_in_place` added (the researcher now scopes by region and the feed has a competitor section). Editable in config without a migration. The owner may change it on review. |
| 4. Undated signals stay visible with "Date unknown" | "yes" | The Freshness facet defaults to All; the badge says Date unknown. |
| 5. Scheduler arranged separately | "yes or not i am not sure, for the user experience choose the option they like or not" (assistant decides) | Not switched on by S2: it spends search and AI quota with nobody watching and needs the server cron and a queue worker, which only the owner can arrange. The Runs tab says plainly that runs are started by hand and shows last-run age and cost, so the price of turning it on is visible first. Revisit when the owner arranges ops. |
| 6. Leave the empty Signal Inbox as it is | "yes" | Nothing moved. Main's `2026_12_06_110000_remove_signal_inbox_menu_row` is therefore not run on dev by this work. |
| Pace | "fast the work little but do not cut the plan work and do not reduce the quality; maximum parallel and smartly; deadline today" | Six parallel build tracks, each on its own files, built to one written contract (`slices/S02-contract.md`); every track proves its tests can fail; every commit reviewed by the assistant. No plan item cut. |

### 2026-11-02 (evening) — Answers to the S1 design gate and S0 questions (owner: Milan Baldaniya)

| Question | Answer | What it means |
|---|---|---|
| Where does the Product Master live | "Admin only, but under the CRM module, not under the profile settings" | Menu row `/module/crm/product-master` under root 199 (Sales & Marketing), label "Product Master", Administrator profile only. The mock-up's "Settings › Products" was wrong and is corrected. |
| Words | "I have no idea, use sub-product" | A top-level group is a **product**, an item inside it a **sub-product** (the Signals run screen already says Main Product and Sub-Product). `g2g_product_master.kind` is `product` or `sub_product`. |
| The 32 signals with no product | "Check by yourself" | Checked: `_evidence/s1-design-gate-checks-2026-11-02.md`. S2 assigns product by business_fit, then by the matched offer (51 of 72); the other 21 stay "no product yet" for a reviewer. |
| Prices | "A quote is priced from a price book; the price book connects with products and services" | Prices live in price books. The Product Master shows them read-only and keeps a base price as the fallback. There are no price books, products or quotes yet. |
| D6 SMS master switch | "I have no idea" (assistant decides) | No switch now: SMS is already opt-in per organisation through its gateway (only tenant 3, Healthcare, has one). The agent layer gets no SMS tool. Consent and caps come in S10. |
| The 10,000 credits | "Yes" to "Tavily, Context.dev, or both" | Both providers. One cap row per provider. **Open for S5:** 10,000 each, or one shared pool. |
| Schema export and Docker | "Use db3; we do not have Docker" | No Docker rehearsal and no schema export. db3 (live) is the only MariaDB 10.1, so each live migration is first proven with a scratch table there, after the owner's "go". Dev needs no go. |
| Production server flags | "The email sender is set up in account settings (screenshot); the email master is set up; SMS and WhatsApp not confirmed" | Measured: only tenant 6 has outbound email enabled, one default mailbox; SMS "Not set up" for tenant 6. SMS and WhatsApp stay off for outreach. Env flags and queue/cron are still unknown and are not needed to build. |
| Continue | "Start the next work, continue working" | S0 is closed. The S1 design is treated as approved with the changes above and building starts on the dev database. |

### 2026-11-02 — Answers to the six planning questions (owner: Milan Baldaniya)

| Question | Answer | What it means |
|---|---|---|
| Direction ("is option B the direction?") | Not answered as a letter. The owner could not find an "option B" on the page, and answered the service question instead (next row). The pages now say "hybrid". | The two pages used letters differently; both now drop them. |
| Pilot organisation | Tenant 6 | New screens are granted to tenant 6 only until the owner says otherwise. |
| Monthly budget for Context.dev, Tavily and the model | "Currently set the 10000 credits; we will find an open-source tool after." | Provider and unit unconfirmed (assumed shared by Tavily and Context.dev; model cost separate). Built as a per-tenant cap setting (`g2g_usage_budgets`), not a constant. **Open: confirm unit and provider.** |
| Who runs a new TypeScript service next to the PHP system | "All code and database base in the current hp_erp and g2gv0 and db3 and db database." | No separate TypeScript service and no new database. The agent layer is PHP inside hp_erp; screens and chat are g2gv0. |
| Which actions may an agent take without a person | "My default is none, except adding notes and tasks. Admin change, add it, decide it." | Autonomy is an admin-editable setting per organisation and action (slice 7). Defaults: add note and add task = alone; everything else approval or lower. |
| Which screens retire, and after what soak period | "We will plan it one by one; I do not know yet." | New screens are built beside the old ones. Nothing is hidden or removed until the owner records a decision for that screen. |

### 2026-11-02 — Working rules requested by the owner

- Every session must update the logs and work progress properly, locally (this folder). The work is too big for seven phases and sessions lose context.
- Start with the product: two products exist (Signals generation, and Sales and Market Intelligence); their product forms and fields differ; the same problem repeats in other modules. Plan the CRM as branches (campaign to lead, product to lead, lead to contact, organisation, playbook, outreach, chat, agents) and redesign the front end one feature at a time, fully, to enterprise level.
- Update the published artifact and make the plan more detailed, including database changes (add, alter, update).
- Show the pages in light mode.

### 2026-11-02 — Constraints carried over from the research prompt

- G2G Signals live data (db3, tenant 6) is read-only for research.
- Nothing may send email or messages.
- Treat all downloaded code as untrusted.
- Never print secrets or API keys.
- No agent worktrees.

## Plan decisions (made by the assistant, awaiting owner review at the gate)

| Date | Decision | Why | Revisit at |
|---|---|---|---|
| 2026-11-02 | Product Master is a thin index table pointing at the four existing product stores; it is not a merge | Ten tables hold real foreign keys to `crm_products`; the earlier federation decision (`Docs/sales-marketing-merge/DECISIONS.md`) stands | S1 design gate |
| 2026-11-02 | New shared tables use the `g2g_` prefix; no foreign keys; no JSON columns; tenant column BIGINT UNSIGNED NOT NULL with 0 = shared | MariaDB 10.1 limits and repo convention | `DB-RULES.md` (S0 task 3) |
| 2026-11-02 | Agent gateway: no send, delete or merge tool is ever registered; an admin cannot enable them | Safety ceiling recommended; the owner may overrule | S7 design gate |
| 2026-11-02 | Reuse the existing `gtm_workflows` engine for agent triggers rather than building a second trigger system | It already has idempotency per workflow and event, retries and approvals | S12 design gate |
| 2026-11-02 | The GTM outreach engine is the one that sends; the CRM approvals screen is only a ledger | GTM has the approval hash, daily cap and bounce handling | S10 design gate |
| 2026-11-03 | S3 merge of two CRM organisations: every table that stores an organisation id is listed ONCE in `OrganisationReferences` and moved by `OrganisationMerger`, tenant-scoped; the older live Organisation 360 identity wins and the removed one's GTM/Signals pointers move to it (not nulled); identical relationship edges are de-duplicated; rows with a NULL tenant are never moved; append-only history (`g2g_event`, `g2g_audit_log`) is not rewritten | Nulling pointers would orphan links; a hard-coded list is how the old merge lost 12+ tables; history must keep what was true when written. Decided by the assistant under the owner's "decide yourself, 360-degree view" instruction | When the owner reviews S3, or when a new table points at an organisation (the scan test fails first) |
| 2026-11-03 | **No duplicate screens.** An object that already has a screen (Organizations, Products) gets its new abilities INSIDE that screen (tabs, a scope switch, columns, actions); a new sidebar entry is only for something with no existing home. This replaces the 2 Nov answer "new screens are built beside the old ones" for Product Master (S1, menu 505) and Organisation 360 (S3, menu 507): both leaves are removed and their data layers stay as plumbing. Signal Review (S2, menu 506) stays as a separate line because the owner approved it at the S2 gate | Owner, after seeing the sidebar: "we already have the product sub module ... you should update it"; "we already have the organization on the side bar". Evidence: the menu tree on dev showed 3 product screens and 2 organisation views | When the owner reviews S2 (Signal Review vs Company Opportunities) |
| 2026-11-03 | **Ingestion Engine (menu 491, `/ingestion`) and Partner Network (menu 493, `/partners`) under Market Signals are protected: nothing in this effort may remove, hide, rename or change them** (menu rows, routes, controllers, data). Product Portfolio (492), Company Opportunities (490) and Signal Inbox (501) are likewise untouched by the fold-in work | Owner instruction. Checked on dev the same day: both rows present and active, and no changed file touches them | Only if the owner says otherwise |
| 2026-11-03 | **The Signals screen design is frozen until the very end** (owner: "the signals screen design currently on the live, means on the harshit's branch, do not change it yet ... the 4 tabs should be as it is in the same design ... what you can change inside it is the business logic, our product master you can change on the product portfolio"). Live design = `origin/main` (Harshit's branch is fully contained in it; checked: `git log origin/main..origin/harshit` is empty). None of our commits touch the Signals design files (checked: `git log origin/main..milan1` over those paths is empty). Allowed: backend, data, additive API fields, the master feeding Product Portfolio's logic. Not allowed: any restyle, re-tab or move of the four tabs or their components | Owner instruction; the owner will change the design at the end if needed | The end of the build |
| 2026-11-03 | **Signal Review's menu row moves from under Market Signals to the Sales & Marketing root** (`2026_12_10_120000_move_signal_review_menu_row_to_the_crm_root`, dev only until the owner's go). The live Signals navigation (`lib/signals-nav.ts` on `origin/main`: `collapseSignalsNav` returns the Signals parent with `children: []`) drops every child except the four tabs and Signal Inbox, so a Signal Review row under Market Signals would vanish from the sidebar once the live front end is merged. Moving the ROW fixes it without changing the Signals design. The 2026-11-03 answer "a separate Signal Review line" still holds; only its place changes | Found by reading main's navigation code (read in source), not by running it; to be confirmed in a browser after the front end is merged | After `origin/main` is merged into `milan1` |
| 2026-11-03 | **Plan change, with its reason: the first visible agent (the Organisation agent, a thin slice of S7) is pulled forward**, built now on top of the Organisation 360 data instead of after S4 to S6. The facts ledger (S4) and discovery (S5) stay in the plan and stay in order after it | The owner, after a day with no agent visible: "not any agentic feature is still seeing, I like Comp AI ... I want the G2G agentic CRM". The gateway pieces S7 needs (runs, tool-call audit, proposals, autonomy, note and task services) are built once and reused by every later slice | S7 design gate (the rest of S7: tool loop, budgets, the settings screen) |
| 2026-11-03 | S3 identity matching: auto-link only on an exact normalised name or domain, one-to-one, place-guarded (a state or country is not evidence of the same place); everything fuzzy is a human suggestion; a pair a person rejects is never offered again | A false merge costs far more than a missed link; evidence in `_evidence/s3-design-gate-checks-2026-11-03.md` | After the owner labels the sheet of pairs (rescore) |

## Retire decisions (screen by screen)

None yet. Each slice ends with "Old screen: keep, hide or retire, decided by the owner on <date>". Default: keep.
