# Master Fields into Field settings, Agent Run History into the Run Log (26 Nov 2026)

Owner words: "in the crm we have the master fields sub module so we want to merge it with /platform-services/fields-configuration ... as we merge the automation and workflow same merge the master fields to field settings and remove from the sidebar; same merging agent run history to /module/agentic-ai/run-log and remove the agent run history module from the crm".
Owner answers: the moved Master Fields tabs open to administrators only; delete the Master Fields menu row on dev and db3.

Tags: **verified** = run in this session, **read in source** = read in the code, **inferred** = reasoned, not run.

## What was found before changing anything

| Claim | Tag | Evidence |
|---|---|---|
| Master Fields is a six-tab console (a "Custom Fields" tab that only linked out, plus Picklist Values, Tax Rates, Lead Scoring & Assignment, Web-to-Lead, SLA Policies) whose five real tabs are self-contained components that fetch their own data | read in source | `app/module/crm/master-fields/page.tsx` before this change; `components/domain/crm/crm-*-admin.tsx` |
| No API of those five consoles is gated by the Master Fields menu row: picklists by the edit right of the module that owns the list, tax rates by Quotes, lead scoring and web-to-lead by `menuright:201` (Leads), SLA by Tickets. Only `CrmGroupSidebarCommand` named `master-fields` in the backend | read in source | route files and controllers; grep of `app/` |
| Row 202 "Master Fields" (parent 446, no children) has 31 rights on dev AND db3: 13 with view/add/edit/delete (the administrator profiles), 1 with view/add/edit, 17 view only | verified | `before-image-dev.json`, `before-image-db3-live.json` (independent SELECTs, taken before the command was run) |
| The agent API is gated by the Agent Run History row itself (`AgentAccess::RUN_HISTORY` = `/module/crm/marketing/agent-runs`; `routes/agent_gateway.php`: runs, proposals, autonomy, kill switch). Deleting row 486 would lock every one of those endpoints, so it stays | read in source | `app/Domain/AgentGateway/AgentAccess.php`, `routes/agent_gateway.php` |
| Row 486 "Agent Run History" (parent 200, status 1) holds 32 rights on dev and on db3 and is untouched by this work | verified | SELECT after the dev apply: 32 rights, same row |
| Field settings is gated by the Platform Services CRM row 441 (14 admin grants), so "administrators only" needs no new right | read in source + verified | rights query on dev (menu 441: 14 profiles) |

## What changed

Front end (g2gv0, `milan1`, commit `de7eae7`):
- `lib/platform/fields-tabs.ts` (pure tab rules), `app/platform-services/fields-configuration/_components/FieldsTabs.tsx`, `page.tsx`: for `?module=crm` six tabs (Custom fields first, as before), every other module and the central view unchanged with no tab bar; only the open tab is built, so a console reads its data when opened.
- `app/module/crm/master-fields/page.tsx`: 307 redirect to `?module=crm&tab=picklists`.
- `lib/agent/run-log-tabs.ts`, `components/domain/agentic/ag-run-log-page.tsx`: Run Log has "Agentic AI runs" and "Sales & Marketing agents" (the old Agent Run History screen with its Runs / Waiting for you / Autonomy tabs). The second section is offered only to a person the agent API lets in (one `proposals` count call; 403 hides it, and a pasted `?section=sales-marketing` lands on the first section). The section is in `?section=` so the screen's own `?tab=` survives.
- `app/module/crm/marketing/agent-runs/page.tsx`: 307 redirect to the Run Log section, keeping `?tab=`.
- `lib/signals-nav.ts`: both rows are folded out of the drawn sidebar in code and both addresses stay resolvable (`retired`).

Backend (hp_erp, `Milan-2`, commit `ae09c7f0`): `crm:retire-master-fields-menu` (dry run by default, JSON before-image first, one transaction, idempotent, `--restore`; selects ONLY the exact address; 15 tests, mutation proof: widening the address list to include Agent Run History fails 4 tests). `AgentAccess` docblock says why row 486 stays.

## Database

| Host | Step | Result |
|---|---|---|
| dev (202.47.117.220) | independent SELECT before-image | row 202 + 31 rights; row 486 + 32 rights |
| dev | dry run | 1 menu row, 31 rights, matches the SELECT |
| dev | `--apply` | before-image `dev-apply/retire-master-fields-menu-20261126-134339-5437.json`; DONE; SELECT after: 0 rows with the address, row 486 and its 32 rights unchanged |
| dev | restore rehearsal | `--restore` put 1 row + 31 rights back with their own ids; compared with the independent before-image: menu identical, rights identical |
| dev | `--apply` again | before-image `dev-rehearsal/retire-master-fields-menu-20261126-134357-11d5.json`; DONE; a further dry run says "Nothing to do" |
| db3 (128.199.17.97) | independent SELECT before-image | row 202 + 31 rights, no children; row 486 + 32 rights |
| db3 | dry run | `db3-dry-run-before-apply.txt`: 1 menu row and 31 rights, matches the SELECT |

See `DB-CHANGELOG.md` row 82 for the db3 apply and its verification.
