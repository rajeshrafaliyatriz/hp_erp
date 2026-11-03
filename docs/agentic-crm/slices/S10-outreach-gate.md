# S10: Playbooks and outreach (backend track S10a first: the one gate both mailers must pass; screens later)

Plan: `../PLAN.md` section 7, S10. Written 4 Nov 2026 (about 05:25 IST). Status: **brief written, track S10a starting**. Dev only; live is never touched. **Sending stays OFF everywhere: no code in this slice sends, schedules or enables sending.**

## Verified starting facts (read in source by the caller, 4 Nov)

- Two mailers exist: `app/Domain/Gtm/OutreachMailer.php` (GTM, `send(GtmOutreachMessage $m, ?int $actorId, bool $scheduled)`) and `app/Services/Crm/CrmOutreachMailer.php` (CRM, `send(object $emailRow, ?int $actorId, string $linkedType, int $linkedId)`). Each already has a refusal ladder (`$refuse(status, code, message)`): the flags (`config('gtm.outreach.sending_enabled')`, `config('crm.email_sending_enabled')`, both OFF by default), the platform gate, an unreachable-unsubscribe check, the recipient's own status, the suppression list and a daily cap; each appends a signed unsubscribe link and a `List-Unsubscribe` header.
- **Neither mailer calls `App\Domain\Facts\ConsentGate`** (grep, 4 Nov: it is used only by the lead consent filter, the consent controller and the recorder). So `crm_consent_preferences` and the CRM `consent_status` / `suppressed_*` columns are not enforced by either sender today (defect D7, as the plan said).
- `gtm_outreach_messages` has a state machine (approved hash, attempts, outcome) and, per the plan (agent-read, re-verify), no index on `provider_message_id` or on `(status, scheduled_for)` (defect D19) although code filters on both. `gtm_playbooks`: tenant NULL means a platform default.

## Goal

ONE `OutreachGate` that both mailers call as one more step of their existing refusal ladder, so that nothing can be sent that carries a banned phrase, an unresolved placeholder, more than one link, a claim nobody approved, or that goes to someone the consent gate or the suppression lists say no to; every check and its result is recorded per message so a person can see why a draft was blocked. With sending OFF nothing changes for anyone: the gate only adds reasons.

## Decisions (made by the assistant under the owner's "decide yourself" instruction; each reversible)

1. **The gate is a refusal reason, never a sender.** It runs inside each mailer's `send()` AFTER the existing flag checks and BEFORE the transport, answers like the other refusals (`422`, code `gate_blocked`, the failing checks listed), and is also callable on its own (a "preview the checks" endpoint) without sending anything.
2. **Deterministic checks only, no model call**: banned phrases and a length cap from config; at most one link in the body (the signed unsubscribe link the mailer adds does not count); no unresolved placeholders (`{{...}}`, `{...}`, `[...]` that look like a merge field, `<first_name>`); claims (below); consent and suppression through `ConsentGate` for the email channel plus the GTM contact status and `gtm_suppressions`; the unsubscribe-reachable check the mailers already make is reused, not duplicated.
3. **Claims**: `g2g_product_claims` holds the sentences an organisation has approved for a product. A draft that names a product may state a numeric, percentage or superlative claim (a sentence with digits followed by `%`, "guarantee", "only", "best", "#1", "fastest", "100%" and the like: a documented list in config) only if that sentence appears, normalised, in an APPROVED claim of that product; otherwise the check `unapproved_claim` fails and names the sentence. No claim table rows means no numeric or superlative claim passes (the safe default).
4. The agent hooks of the plan (`draft_outreach`, `submit_outreach_for_approval`) are NOT in this round; no send tool is ever registered.

## Database (additive; DB-RULES in full; new migration files sort after `2026_12_11_140200_...`: name them `2026_12_11_15xxxx_...`; one file at a time with `php artisan migrate --path=<file>`)

- NEW `g2g_outreach_gate_checks`: `id`, `sub_institute_id` BIGINT UNSIGNED NOT NULL, `message_id` BIGINT UNSIGNED, `message_table` varchar(30) (`gtm_outreach_messages` or `crm_emails`), `check_key` varchar(40), `passed` tinyint, `detail` varchar(300), `checked_at`, `created_at`; index `(sub_institute_id, message_table, message_id)`. A re-check adds rows; the newest set per message is the current one (a `run_id` char(36) per evaluation groups a set).
- NEW `g2g_product_claims`: `id`, `sub_institute_id`, `product_master_id`, `claim` varchar(500), `source_url` varchar(1000) NULL, `status` varchar(12) (`proposed`, `approved`, `retired`), `approved_by` NULL, `approved_at` NULL, timestamps; unique `(sub_institute_id, product_master_id, claim_hash)` with `claim_hash` char(40) (the normalised sentence).
- ALTER `gtm_playbooks` (tenant rows) and `gtm_outreach_messages`: `product_master_id` BIGINT UNSIGNED NULL, indexed with the organisation. Index `gtm_outreach_messages (provider_message_id(191))` and `(status, scheduled_for)` (defect D19; prefix because the column is varchar(255)). Check `SHOW CREATE TABLE` first: skip any index that already exists.
- No data update. No menu row.

## Contract (additive; no existing route changes shape)

- Both mailers' `send()` gain one refusal: `422 {code: 'gate_blocked', message, checks: [{key, passed, detail}]}` (the existing refusal shape of each mailer, plus `checks`), reached only when the earlier ladder steps pass.
- `GET /api/outreach/gate?table=&id=` -> `{status:1, data:{message:{table,id}, passed:bool, checks:[{key,label,passed,detail}], checked_at}}` (evaluates now, stores a set, sends nothing; rights: VIEW on the Outreach Approvals row `/module/crm/marketing/outreach-approvals` OR the GTM outreach row, read the existing rights of the two screens; another organisation's message is a 404).
- `GET/POST/PUT /api/outreach/claims` (list, propose, approve or retire; approve and retire need EDIT on the Products row; another organisation's product is a 422, its claim a 404).

## Acceptance

1. With sending OFF both mailers answer exactly as before (their existing tests stay green); with the flags forced ON in a test and `Mail::fake()`, a message that fails any check is refused with `gate_blocked` and NOTHING is handed to the transport; a clean message still reaches `Mail::fake()` exactly as before.
2. Each check has a failing and a passing test: banned phrase, too long, two links (and one link plus the unsubscribe link passes), each placeholder form, an unapproved claim (and an approved one, normalised for case and spacing), an opted-out contact through the CRM `consent_status`, through `crm_consent_preferences`, through `email_opt_out`, through `gtm_suppressions` and through the GTM contact status.
3. The recorded set per evaluation and the newest-set rule; cross-organisation 404 on every id route; wrong right 403.
4. Migrations on dev with `SHOW CREATE TABLE` before and after saved; indexes only where absent; `down()` tested.
5. A static test that nothing in the new code calls `Mail::`, `Http::`, `Notification::`, `->send(`, `dispatch(` or touches a flag; sending remains impossible from the gate.
6. Front end (a later small track, S10b): the existing Outreach Approvals screen shows each draft's checks in words and a "check again" button; nothing new in the sidebar; no send button is added.

## Work log (append only)

- 2026-11-04 about 05:25: slice file written.
