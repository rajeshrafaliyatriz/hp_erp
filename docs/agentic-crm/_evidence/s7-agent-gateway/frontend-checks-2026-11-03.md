# S7 (thin) front end: the checks that were run

- Produced by: commands run from `C:\Users\MILAN\Downloads\g2gv0` (branch `milan1`), 3 Nov 2026, 21:00 to 22:30 IST. No server was started and no browser was driven: these are tests in a fake DOM (jsdom 26) plus the type checker and the linter.
- Files of this track in g2gv0 (all new except `agent-runs-view.tsx`, which was rewritten): `services/agent/{types,api-types,errors,agent-service}.ts`, `lib/agent/{model,fixtures,fake-service,focus,a11y,assertions}.ts`, `lib/agent/{model,agent-service}.test.ts`, `lib/agent/{org-agent-panel,agent-runs,a11y}.test.tsx`, `components/domain/crm/{org-agent-panel,org-agent-parts,agent-run-detail}.tsx`, `components/domain/crm/agent-runs-view.tsx`.

## Tests

`node --import tsx --test "lib/agent/*.test.ts" "lib/agent/*.test.tsx"` (the same runner and glob style as `npm test`, narrowed to this track's folder):

```
# tests 123   # suites 25   # pass 123   # fail 0
```

| file | tests | what it covers |
|---|---|---|
| `model.test.ts` | 27 | every word and rule the screens use: action and status words, the cost in rupees (never 0 for unknown), tokens, time, durations, the safe-link rule, the run gate (right, switched off, no model, the day's cap), what a run and a decision say, merged identity, failures, the history filters, the audit trail in words |
| `agent-service.test.ts` | 14 | paths, methods and bodies of every call; ids encoded as text; a refusal keeps its `code` and a 409's proposal; the reason trimmed; the CRM-to-identity lookup through the Organisation 360 API |
| `org-agent-panel.test.tsx` | 53 | the panel: opening, error with Retry, forbidden, not found, no session, never run, late answers dropped; the page moving on to another organisation (starts clean, a late result changes nothing); who may run it and why not; running (busy, ignored second press, notice, focus, page told); accepting (applied, failed, refused for any reason reads the panel again, already applied, merged into another identity); dismissing (form, reason sent, Escape and Keep it return the focus, one form at a time, refusals); what a person is shown (Agent mark, words not colour, unknown cost, show all, links only for paths inside the application); text never becomes markup or a link; real buttons; axe-core in six states plus three more with the form open, a message showing and while running |
| `agent-runs.test.tsx` | 26 | Agent Run History: rows, totals from the server, none of the old demonstration data, whose clock the days use, empty, no session, error and Retry, forbidden; status and day filters, an end before the start is said and not asked for, back to page 1 on a filter change, paging, a late answer dropped; a run's dialog (brief, proposals, Agent mark, audit trail with refusals, not found, Retry, Escape returns the focus, a late answer for a run that was closed is dropped, text not markup); axe-core on the list in five states and on the open dialog |
| `a11y.test.tsx` | 3 | the accessibility check can fail (a nameless button, an image with no alternative, an unlabelled field, a nameless link and select are all reported) and reports nothing for fine markup, so "no violations" in the other files means something |

Accessibility is checked with axe-core 4.12.1 over the fake DOM with the WCAG A and AA rules that look at markup, names, roles and relationships. **Colour contrast is not checked** (jsdom computes no styles); that needs a real browser. (An early version of the accessibility self-check selected the wrong element, an empty one, and reported nothing: it was found because the check has a test that must report violations.)

Mutation proofs of these tests: `mutations-2026-11-03.md` (24 front-end mutations, all killed).

## eslint

`node node_modules/eslint/bin/eslint.js components/domain/crm/org-agent-panel.tsx components/domain/crm/org-agent-parts.tsx components/domain/crm/agent-run-detail.tsx components/domain/crm/agent-runs-view.tsx services/agent lib/agent`: **0 errors, 0 warnings** (JSON format count over 19 files; the react-hooks rules are on).

## tsc

`node node_modules/typescript/bin/tsc --noEmit -p .` (1 min 44 s). At the start of the session exactly the 14 known errors (account-360 7, crm-ai-assistant 4, opportunity-ai-coach-tab 2, signal-inbox-view 1). At 21:25: 35 errors, the same 14 plus 21 in files of track X's in-flight work (`app/module/crm/organisation-360/[id]/page.tsx` 1, `org-account-detail-page.tsx` 3, `org-account-detail-tabs.tsx` 1, `org-account-list.tsx` 2, `lib/org-accounts/model.test.ts` 13, `lib/org-accounts/org-account-detail.test.tsx` 1). **None in any file of this track.** At 22:40 (final run): **7 errors**, all in files this track never touched and all of the baseline's list (`crm-ai-assistant.tsx` 4, `opportunity-ai-coach-tab.tsx` 2, `signal-inbox-view.tsx` 1): track X had meanwhile fixed `account-360.tsx` (7) and its own 21.

## components/ui

This track did not touch `components/ui`. (`git status` shows changes in `alert-dialog.tsx`, `breadcrumb.tsx` and `confirm-dialog.tsx`: they are another track's.)

## Real-browser checks: none were run (no server was started, per the brief). The list of what to check is in the slice file.
