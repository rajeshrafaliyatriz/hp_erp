# Schema and code reading — 2026-11-02

Read from migrations, models, controllers and screens by read-only subagents (Explore and Plan) plus my own spot-checks. **No database was contacted.** Tags: [M] read in migration, [C] read in code, all `agent-read` unless marked **verified**. Live may differ: slice S0 task 4 measures it. Full reports: see `_evidence/README.md`.

Roots: BE = `C:\Users\MILAN\Downloads\hp_erp`, FE = `C:\Users\MILAN\Downloads\g2gv0`.

## Environment

- Dev connection (default): MariaDB 10.11.9 at 202.47.117.220. `live` connection: MariaDB 10.1.48 at 128.199.17.97. Both `strict=false`.
- `Schema::defaultStringLength(191)`; `doctrine/dbal` not installed (earlier migrations use raw `MODIFY COLUMN`); `Schema::hasColumn/getColumnListing` patched for 10.1 at runtime (`AppServiceProvider`, `LegacyMariaDbSchemaGrammar`) per one report, while repo comments say `SHOW COLUMNS … LIKE ?` fails on live; `JSON` type is not patched.
- Every tenant column is `sub_institute_id` (BIGINT UNSIGNED); the token owner's tenant always overrides the request (`ResolvesApiIdentity`).

## Products

- `crm_products`: `item_type` (product or service), `product_no` unique, `name`, `sku`, `category`, `gl_account`, `unit_price`, `cost_price`, `commission_rate`, `currency`, `tax_rate_id`, `is_active`, `vendor` (free text), stock fields, `image`, `assigned_to`, soft delete. Ten tables hold real FKs to it (quote, sales order, invoice, PO line items, tickets, assets, FAQs, opportunity products, price book items, bundle items). `crm_campaigns.product_id` is loose. Merge repoints only `crm_opportunity_products` and `crm_quote_line_items`.
- `g2g_product_offers` (sub-products, 25 seeded in `config/portfolio_seed_data.json`): `sub_institute_id` NULL = shared foundation row; `offer_id` (not unique), `name`, `parent_product` (category from `config/g2g_taxonomy.php`: G2G, EB, Scholar K-12, HE, Bundle), `needs_solved`, `primary_segments`, `trigger_signals`, `icp_config` (longtext JSON `{b2b,b2c,b2g}`), `readiness_*`, `pricing_model` (free text).
- `g2g_signal_products` (own top-level product): unrun migration (D12). Built-in products: `config/signals_products.php` (`scholarclone`, `enterprise_brain`, `g2g`). `g2g_product_profiles`: one row per tenant (research settings ignored by the scheduler). ICP: `g2g_icp_profiles` (+ versions), also copied into `g2g_product_offers.icp_config`.
- Forms in FE: `components/domain/portfolio/new-product-dialog.tsx`, `product-form-dialog.tsx`; `components/domain/crm/create-product-modal.tsx`. GTM has no product model.

## Signals

- `g2g_company_opportunities` (70 columns): see plan appendix A. Review columns `review_status` (New / Reviewed / Dismissed / Follow-up), `review_notes`, `reviewed_by`, `reviewed_at`; `pipeline_status` never changes after `NEW`; dates `event_date`, `source_published_at`, `observed_at`, `expires_at`; `assessment` JSON holds `evidence.date_status`, `date_provenance`, `quote_verified`, `corrections[]`, `sub_product`.
- Scheduling: only `routes/console.php`; `signals:research` every 15 min if `SIGNALS_SCHEDULED_RESEARCH`; tenants from `SIGNALS_TENANTS` or `g2g_target_accounts`; time `SIGNALS_RESEARCH_TIME` 10:00 Asia/Kolkata; manual runs queue `RunOpportunityResearchJob` on queue `signals` (900 s, 1 try), 10-minute per-product cooldown.
- Runs record counts, providers, model, error code, product key, ICP id, stage; no tokens, credits or cost. Tavily and Brave keys are env-only.
- FE files: `components/domain/signals/` (3,031 lines; `scholar-signals-view.tsx` 689, `ingestion-engine-view.tsx` 639, `company-opportunities.tsx` 434), `components/domain/portfolio/` (3,934 lines; `partners-view.tsx` 1,051, `portfolio-view.tsx` 831), services `services/signals/opportunities.ts` (734), `services/portfolio/portfolio-service.ts` (219). Legacy `signalsService` calls routes that no longer exist.

## Event store

- `EventRecorder` (`app/Services/Events/EventRecorder.php`) is the only writer; requires tenant > 0; no update or delete. `uq_event_idem (tenant, idempotency_key)`; `GtmAudit` catches the duplicate-key exception and returns the existing id; key convention `crm.lead.created:{tenant}:{leadId}`.
- No Signals code emits events. Lead types: `crm.lead.created/qualified/ready/converted/replied/reply_classified/meeting_proposed/meeting_booked/updated`. Opportunity: `crm.opportunity.created/stage_changed/inactive/overdue/updated`. About 55 `gtm.*` types in `app/Domain/Gtm/GtmEvents.php`, `app/Services/Events/EventCatalogue.php`. `crm.*.updated` types are not registered but still reach `g2g_audit_log` (`AuditLogProjector::handles()` returns true for any type).
- A consumer defines `CONSUMER`, `handles()`, `project()` or `dispatch()`; is declared in `EventCatalogue::SHIPPED`; added to `ReactEvents::REACTORS` (every 10 min) or `ProjectEvents::PROJECTORS` (every 5 min). A failed reactor event is recorded and not retried.

## CRM records (key facts; full column lists in the transcript)

- `crm_leads`: tenant **nullable**; `lead_no` unique global; `description` TINYTEXT; `lifecycle_status`, `lead_score`, `lead_score_basis` (text JSON); consent columns never written; converted ids; `source_company_opportunity_id`; no product column.
- `crm_contacts`: `email` varchar(100); `contact_no` unique; `organization_id` FK; consent columns.
- `crm_organizations`: unique `account_no` and `(tenant, name)` (counts soft-deleted); `email` varchar(100); no social columns; about 20 referencing tables.
- `crm_opportunities`: `opportunity_no` unique, `name`, `organization_id`, `sales_stage`, `amount`, `closing_date`, `close_reason`; no contact column (`crm_opportunity_contacts`); `crm_opportunity_stage_history` has no tenant column.
- `gtm_accounts` (unique `(tenant, domain)`, `crm_organization_id` FK, `company_id` loose, `stage='customer'` is the GTM Customers data), `gtm_contacts` (`full_name`, `email`, `phone`, `linkedin_url`, `status`; no code sets `source='research'`), `gtm_deals`, `gtm_deal_stage_history`, `gtm_activities` (the GTM timeline).
- Notes: `crm_{lead,contact,organization,opportunity,ticket}_comments` (parent FK cascade, tenant, `comment`, `created_by`); there is no `crm_notes`, `crm_activities` or `crm_tasks` table. Tasks are `task_management_calendar_events` (`linked_type` UPPERCASE `LEAD|CONTACT|ORGANIZATION|OPPORTUNITY|TICKET`, `linked_id`, `status` default `Planned`, `syear` NOT NULL, no index on `(linked_type, linked_id)`).
- Write paths: HTTP `POST /api/crm/{leads|contacts|organizations|opportunities}/{id}/comments` (`HasCrmComments::addComment`, needs `edit`); `LeadActivity::note` (lead only, direct insert, `created_by` NULL, "[Automation]" prefix, 1980-char cut); `LeadActivity::taskFor` (five CRM types, 30-minute event at 09:00, duplicate guard, `syear` from the `academic_year` table) over `CalendarEventWriter::create` (no rights check). The browser sends the academic year while the server default is the calendar year, which makes events unopenable ("Event not found").
- Duplicates: `GET …/duplicates` and `POST …/merge` for leads, contacts, organizations (and vendors, assets, products); merge fills blanks then soft-deletes; no unique index on email or phone anywhere in CRM.
- Custom fields: `tblcustom_fields` (definitions), `tblfields_data` (options), `g2g_custom_field_values` (`record_table`, `record_id`, `field_id`; unique together); allowed `record_table` list in `config/platform_services.php`; `gtm_*` tables are not allowed. `crm_picklist_values` is global (no tenant column).
- Polymorphic type vocabularies are inconsistent: UPPERCASE in calendar events, `document_library_links`, `crm_email_links`; lowercase in `crm_campaign_targets`, `crm_sms_log`, `crm_consent_preferences`, `crm_entity_relationships`, `crm_knowledge_sources`; table name in `g2g_audit_log`.

## Campaigns, outreach, automation, agents

- `crm_campaigns` (`campaign_no` unique global; `product_id` loose), `crm_campaign_targets` (lowercase `target_type`, touchpoint timestamps `sent_at`, `opened_at`, `replied_at`, `meeting_booked_at`, `opted_out_at`, `converted_at`), `crm_outreach_approvals` (ledger; `markSent` flips a status; no send path consults it), `crm_emails` (+ `message_id`, `direction`), `crm_email_links`, `crm_email_templates`, `crm_sms_log`, `crm_saved_views`, `crm_inbound_events`, `crm_lead_followups`.
- GTM: `gtm_segments`, `gtm_campaigns`, `gtm_campaign_members` (`contact_id` 0 = whole account), `gtm_playbooks` (+ `gtm_playbook_versions`; tenant NULL = platform default; unique `(tenant, slug)`), `gtm_outreach_messages` (state machine, `approved_hash`, attempts, outcome), `gtm_outreach_attempts`, `gtm_suppressions`, `gtm_inbound_events`, `gtm_analyses` (every model call), `gtm_workflows`, `gtm_workflow_runs` (unique `(workflow_id, event_id)`), `gtm_workflow_run_steps`.
- Sending: `OutreachMailer` (GTM) and `CrmOutreachMailer` (CRM) refuse log/array mailers and public-URL-less configs; flags all default false (`G2G_NOTIFY_EMAIL`, `school_setup.outbound_email_enabled` per tenant, `gtm.outreach.sending_enabled`, `gtm.outreach.scheduler_enabled`, `crm.email_sending_enabled`); caps `gtm.outreach.daily_cap` 50, `crm.email_daily_cap` 50. No WhatsApp or phone sender exists. SMS has no flag (D6).
- Agents: five GTM agents in `app/Domain/Gtm/Agents` (prospecting, outreach, deal coach, revops, customer success; read-only on business records; write `gtm_analyses`; runs in `agentic_agent_runs`), CRM agents in `app/Domain/Crm/Ai` (D4), all through `GtmAiRunner` → `AiModelClient::complete('gtm', …)` (single-shot, no tools). `crm_agent_runs` is written only after success; failures are not recorded.
- Automation: `LeadAutomationPresets` (24 workflow definitions per the code, 22 per one doc line) installed as drafts by `crm:lead-automation-install`; engine `app/Domain/Gtm/Workflows/WorkflowEngine.php`; runner `GtmWorkflowRunner` driven by `events:react`. `crm_automation_rules` has CRUD only, no executor.
- Scheduler (`routes/console.php`): `events:project` (5 min), `events:react` (10 min), `gtm:tick` (5 min: recover stuck sends, `sendDue` off by default, workflow retries, `LeadFollowUp::processDue`, `OpportunitySweep`, `InvoiceSweep`, forecast and health snapshots), `crm:tickets-escalate-sla` (hourly), `reports:send-scheduled` (15 min), `ai:sync-modules` (daily 03:00), `signals:research` (only if enabled).
- Inbound: `POST /api/gtm/inbound` (HMAC `X-GTM-Signature`, 300 s replay window, `GTM_INBOUND_WEBHOOK_SECRET`) and `POST /api/crm/inbound` (`X-CRM-Signature`, leads only). Empty secrets return 503.

## AI usage and credentials

- `ai_usage_events` (provider, model, tokens, `estimated_cost_usd`, `related_type`, `related_id`, `ai_module`), `ai_usage_quotas` (unique `(tenant, ai_module, period)`), `ai_api_keys` (`api_key` stored in plain text, trimmed only), `ai_module_model_bindings`, `g2g_integration_credentials` (`Crypt::encryptString`, unique `(tenant, provider_key)`), `g2g_platform_task_runs` (every scheduled tick, including `signals.research`).
- `AiModelClient::complete()` is single-shot: no tool-calling anywhere under `app/` (searched for `tool_calls`, `function_call`, `tool_choice`).

## Menu and rights

- `tblmenumaster_g2g` (`parent_id`, `level`, `access_link`, `sort_order`, `status`); root 199 `/module/crm` shown as "Sales & Marketing" (flattened by `2026_12_06_100000_unify_sales_marketing_pipeline_lifecycle`, which ran on dev only per its notes); GTM root id is 441 on dev and 438 on live. `tblgroupwise_rights_g2g` + `app/Support/MenuRight.php`. CRM routes use `platformright:/module/crm/...` (272) and `menuright:` (30); GTM and Signals use `ModuleGate`, with menu-rights mode off by default (`GTM_USE_MENU_RIGHTS`, `SIGNALS_USE_MENU_RIGHTS`). No add/edit/delete flags reach CRM screens, so buttons show for everyone and the API answers 403.

## Frontend inventory (CRM, GTM, Signals)

- About 37,000 lines in 169 component files plus about 3,000 lines of route files and about 6,000 of services and types; 71 route files; 18 list views, 17 detail pages, 17 create modals, 20 near-identical `[id]/page.tsx` wrappers.
- Content map: `hooks/content-map-crm.ts` (module id `'199'`, 52 entries, 38 components) loaded by `hooks/use-content-map.ts`; every `app/gtm/*` page and `/signals`, `/ingestion`, `/portfolio`, `/partners` pass explicit children, bypassing it.
- Shared UI: `components/ui/table.tsx` (CRM lists), `dialog.tsx` (32 CRM files), `searchable-select.tsx` (35 CRM files), `confirm-dialog`, `sheet` and `data-table` exist but are unused by CRM (`DataTable`'s `sortable` flag is declared but unimplemented). `components/domain/crm/crm-saved-views.tsx` (server-backed `crm_saved_views`, stores only search and sort), `crm-bulk-action-bar`, `crm-import-dialog`, `crm-duplicates-dialog`, `comment-thread`, `crm-schedule-tab`, `crm-email-tab`, `crm-history-tab`, `linked-record-picker`. `react-hook-form` and `zod` are installed but unused.
- Three dialects: CRM (`ui/table` + `Dialog`), GTM (`gtm-shared.tsx`, 46 raw `<select>`), Signals/Portfolio (`.g2g-premium` theme, light only).
- Largest screens: Partners 1,051; Portfolio 831; Signal Inbox 816; Opportunity detail 778; Scholar signals 689; Ingestion 639; Automation Rules 550; GTM Prospecting 544; Organization detail 542.
- Design strategy doc: `FE/docs/frontend/design-system-strategy.md`.

## Not read (open)

- GTM forecasting tables (`2026_10_11_100000_gtm_forecasting`) columns.
- `ColumnExists`, `LegacyMariaDbSchemaGrammar` and the exact safe-helper rules (S0 task 3).
- Everything about live state (S0 task 4).
