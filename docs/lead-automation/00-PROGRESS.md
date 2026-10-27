# CRM lead automation (Signal -> Lead -> ... -> Support): record of what was built and tested

Built by extending the existing GTM workflow engine (`app/Domain/Gtm/Workflows/*`; events delivered by `events:react` -> `GtmWorkflowRunner`; clock-driven
parts run from `gtm:tick`). No second engine and no second AI stack. Configure at **Sales & Marketing -> GTM Agents -> Workflows**.
All testing so far is on the shared DEV database (202.47.117.220 / hp_erp), tenant 6, with mail captured in-process and the AI provider faked.
Nothing has been deployed live, no real customer email was sent, and the migrations below exist on dev only.

## What exists now

**24 workflows** (recommended configuration, `LeadAutomationPresets`) realise the seven steps. Install them as drafts, review, activate:

    php artisan crm:lead-automation-install <tenant> --admin=<administrator id> --support-user=<user id>   (add --activate to switch them on)

| Step | Workflows | Needs approval |
|---|---|---|
| 1 Intake | 1a complete lead, 1b missing details, 1c possible duplicate | no |
| 2 Follow-up | 2a new lead, 2b details completed (start the email cadence) | no (email only if CRM email sending is on) |
| 3 Replies | 3a read the reply + task + Contacted, 3b interested -> Pre Qualified, 3c draft an answer, 3d declined -> Lost | no (drafts are never sent) |
| 4 Convert | 4 Qualified -> convert | **yes** |
| 5 After conversion / pipeline | 5a broken links, 5b duplicate organization, 5c decision-maker unknown, 5d new Opportunity, 5e inactive, 5f overdue | no |
| 6 Commercial | 6a prepare draft quote, 6b email quote, 6c sales order, 6d invoice, 6e chase overdue invoice | **yes** for 6a-6d |
| 7 Support | 7 Closed Won -> onboarding ticket | no |

**Why 22 and not 7:** conditions are all-must-match and actions run in order with no branching, so each "if X do A, if Y do B" is its own workflow.
**AI:** two capabilities, both through `GtmAiRunner` (central provider config, metering, `gtm_analyses` audit rows): the CRM Reply Classifier
(`classify_lead_reply`) and the reply drafter (`draft_lead_reply`). Everything else is deterministic code. They are not entries on the Agents screen.

**New workflow actions (17):** add_lead_note, create_lead_task (to lead/organization/opportunity), set_lead_status, assign_lead_round_robin, start_lead_followup,
classify_lead_reply, draft_lead_reply, convert_lead*, add_opportunity_note, create_opportunity_task, set_opportunity_stage, prepare_quote*, email_quote*,
create_sales_order*, create_invoice*, create_invoice_task, create_support_onboarding. (* = consequential: every run waits for an administrator.)
**New triggers:** crm.lead.created/ready/replied/reply_classified/qualified/converted, crm.opportunity.created/stage_changed/inactive/overdue,
crm.quote.created/stage_changed, crm.sales_order.created/stage_changed, crm.invoice.created/stage_changed/paid/overdue.
**Notifications (in-app + email templates, existing pipeline):** customer replied, opportunity inactive, opportunity overdue, invoice overdue, onboarding ticket assigned.
(Ticket SLA breach notifications already existed and are reused.)

## Hard rules the code enforces (each is covered by a test)
- Nothing invented: missing contact details are asked for, never filled; quotes use only product list prices and refuse otherwise; onboarding lists only real records.
- No automatic payment, no automatic invoice status, no automatic deletion, no automatic "Lost" except a high-confidence refusal rule you can remove.
- Money / commitments (convert, quote, quote email, sales order, invoice) need an administrator's approval per run, judged again at approval time.
- Follow-up stops on reply, opt-out (honoured instantly, no AI), conversion, Lost/Junk, a person working the lead, missing address; never restarts.
- Waiting costs nothing (one row per lead, due time); claims and idempotency keys make duplicate events, retries and overlapping ticks harmless.
- Tenant comes from the matched record, never from a payload; other tenants' data is never read or written.

## Files (hp_erp)
Domain: `app/Domain/Crm/Leads/*` (LeadActivity, LeadFollowUp, FollowUpSchedule, LeadReplyProcessor, LeadReplyAi, LeadConverter, LeadCompleteness),
`app/Domain/Crm/Sales/*` (OpportunityEvents/Sweep, QuoteBuilder, SalesDocuments, SalesEvents, InvoiceSweep), `app/Domain/Crm/Support/OnboardingTicket.php`,
`app/Domain/Crm/Automation/*` (presets + installer), `app/Services/Crm/CrmEmailQueue.php`, `app/Http/Controllers/Api/Crm/InboundController.php`,
`app/Console/Commands/CrmLeadAutomationInstall.php`. Changed: GtmEvents, EventCatalogue, WorkflowCatalog/Context/Actions/Engine, GtmScheduler, NotificationDispatcher,
RecipientResolver, CrmOutreachMailer (Message-ID), Crm{Lead,Opportunity,Quote,SalesOrder,Invoice}Controller (events; convert/fromQuote/fromSalesOrder moved into shared services
unchanged), LeadFactory, routes/api.php, config/crm.php.
Migrations (dev only): `2026_12_02_090000` lead follow-ups, `100000` inbound replies (+ crm_emails.message_id/direction/from_address/in_reply_to_inbound_id), `110000`/`120000`/`130000` notification templates.

## Tests and actual results (last full run)
| Check | Result |
|---|---|
| PHPUnit, whole suite | 769 tests, 1 failure: `OpportunityIngestionTest` (pre-existing, missing smalot/pdfparser; identical before this work) |
| PHPUnit unit (new: schedule 11, reply rules 6, completeness 5, event catalogue) | pass |
| Integration (real HTTP + real dev DB): crmleads / crmfollowup / crmreply / crmconvert / crmopportunity / crmcommercial / crmsupport | 47 / 67 / 51 / 34 / 41 / 49 / 28 pass |
| **End to end (crmendtoend)**: Signal promoted -> ... -> onboarding ticket, 22 installed workflows, real webhook + approvals | **39 / 39** (run 3 times) |
| Browser UI (Playwright against the real screens): list, edit, dry run, builder with new actions, activate, Approvals queue + approve | 8 / 8 |
Run any scenario: `php tests/Integration/Gtm/http.php <name>`. They create only "Verify ..." rows and remove them (leftover check: 0).
Pre-existing, not caused by this work: `tests/Integration/Gtm/regress` (2 stale route checks) and `workflows` (crashes at line 222 on the unmodified code too).

## Bugs found and fixed on the way
`crm_leads.lead_no` numbered per tenant but UNIQUE globally (any second organisation's first lead failed); dry run left `title`/`description` placeholders unrendered
(found by the browser check); a lead promoted from a Signal never started the cadence after a person completed it (new `crm.lead.ready`); organization duplicates were
compared by exact name though the database already forbids that (now compares ignoring case, punctuation and Inc/Ltd/Co).

## Not verified / needs you (do not assume these work)
- **Real inbound replies and bounces.** The receiver (`POST /api/crm/inbound`, HMAC) is tested with signed payloads only. No mail provider is connected: pick one that
  forwards to it (normalised JSON, see `InboundController`), set `CRM_INBOUND_WEBHOOK_SECRET`. WhatsApp/SMS have no inbound support in the application, so none was added.
- **Real sending**: needs a public `APP_URL`, an SMTP profile, `CRM_EMAIL_SENDING_ENABLED=true`. With it off, every email is prepared and the cadence pauses honestly.
- **The scheduler must run** (`schedule:work`/cron). The design log found production has never run it; follow-ups, inactivity/overdue sweeps and SLA escalation all depend on it.
- **Live deploy**: run the five migrations on live deliberately (migrate BEFORE deploying code: the mailer now writes `crm_emails.message_id`); nothing was run there.
- **AI quality**: classification/drafts were tested with the provider faked (guards and wiring). A real-model check is not done.
- Quote replies from customers are not matched (only lead emails); a person records Accepted/Rejected on the Quote. No UI panel for the follow-up cadence state (it shows as notes,
  tasks and the lead status). Dry run shows event.* conditions as unmet (it has no event payload).
- The Approvals/Agents panel call `/api/ai/action-requests` returns 403 for this administrator in the browser (unrelated, pre-existing).
