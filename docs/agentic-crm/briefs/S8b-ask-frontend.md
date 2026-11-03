# Track S8b: Ask, FRONT END (brief for a build agent)

Read `COMMON-RULES.md` first, then `../slices/S08-ask.md` (the decisions, the CONTRACT you build against, the acceptance checks), `../DECISIONS.md` (no duplicate screens), `../00-PROGRESS.md`. Written 4 Nov 2026 (03:45 IST). The backend track S8a builds at the same time or just before you: build against the contract and the backend's tests (`tests/Feature/Ask*`, once they exist), not against guesses.

## Goal

The EXISTING CRM chat (`components/domain/crm/crm-chat-widget.tsx`, mounted by `components/shell/gtg-app-shell.tsx`: the purple button at the bottom right) gets the new engine: answers that cite the records they rest on (links that open the record), a conversation list so yesterday's chat can be reopened, honest states (thinking, no data, "I cannot see that", unavailable, daily limit), and an optional "about this record" context when the person is on a lead, contact, organisation or opportunity page. Same button, same place, no new route, no new menu entry. The old endpoint (`/api/crm/agent/chat`) stays reachable through one constant so the owner can switch back.

## Raw material

- `crm-chat-widget.tsx`, its service (`grep -rn "ai-chat" services lib components`), its tests, and how the shell mounts it (read the shell file, do NOT edit `components/shell/*` unless the widget cannot be changed without it; if so, make the smallest change and say so).
- The contract in `../slices/S08-ask.md` and `services/agent/*` (how the agent screens call the API and keep a refusal's whole body: do the same for the 429 `daily_limit` code), `lib/agent/*` (model, tones, fake service, a11y helpers, focus helpers) and `lib/facts/screen-kit.ts` (the whole-screen test kit): reuse them.
- Tones: `lib/agent/tones.ts` / `lib/org-accounts/tones.ts`. Words carry the meaning; text-foreground on a solid surface; only the border takes a status colour; no shared `Badge` / `StatusBadge`; `lib/agent/contrast.test.ts` scans the agent components for the patterns that failed in a real browser (add your files to its list and make it pass).

## Tasks

1. Re-read the contract; list in your slice log every field you rely on, marked optional (the backend may not be deployed: when `POST /api/agent/ask` answers 404 the widget keeps working through the old endpoint and says nothing alarming).
2. Types and service (`services/agent/ask-*.ts`): ask, list conversations, read a conversation; errors by `code`.
3. The widget: messages as text (never HTML; a link only for http(s) or a single-slash path); citations as a short list of links under the answer ("Based on: <label>"); status words for `no_data`, `refused`, `unavailable`; the 429 says "You have used today's questions" with the number; one question in flight (also within one tick); late answers for another conversation dropped; Escape closes the panel and returns focus to the button; Enter sends, Shift+Enter breaks the line; the conversation list reopens an older chat; "New chat". Context: when the current address is a lead, contact, organisation or opportunity page, offer "Ask about this record" (read how the widget knows the page today).
4. Tests: model tests for the rules, whole-screen jsdom tests over a faked service including axe (`lib/agent/a11y.ts`), the contrast scan, a mutation proof for every rule and guard (break, see the named test fail, restore byte for byte, `cmp`); `tsc` (only the known errors elsewhere), eslint `--max-warnings=0` on your files, `git diff --stat components/ui` empty.
5. Reason about 390 px in the code (a table or long unbroken text inside the panel needs `min-w-0` / `break-words`; an `sr-only` element inside a scroll box needs a `relative` box: see `lib/agent/mobile-width.test.ts`); the caller runs the real browser.

## Do not

Edit `components/ui`, any Signals / Ingestion / Partner file, the Leads files (`lead-*`, `lib/leads`, `types/crm.ts`, `services/crm/index.ts`), the Facts files, `agent-runs-view.tsx` and the files track S7c owns (`agent-waiting-*`, `agent-autonomy-*`, `lib/agent/*` except you may ADD new files and add your files to `contrast.test.ts`'s list), `contact-*`, `account-360.tsx`, `organization-*`. No push, no commit, no server, never `npx`. Other build agents work in this repo now: never stage or touch their uncommitted files.

## Report (COMMON-RULES section 7)

Files added or changed, deviations with reasons, test counts and mutation results, which backend fields you could not exercise against real data (the caller will), what the caller must check in a browser. Keep `../slices/S08-ask.md` current (append-only work log, an S8b section).
