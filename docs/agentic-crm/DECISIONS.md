# Agentic CRM — Decisions

Owner decisions and the reasons behind plan choices. Newest first within each section. Every entry has a date and who decided.

## Owner decisions

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

## Retire decisions (screen by screen)

None yet. Each slice ends with "Old screen: keep, hide or retire, decided by the owner on <date>". Default: keep.
