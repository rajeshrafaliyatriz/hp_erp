# Track S7c: Agent Run History gets two tabs (Waiting for you, Autonomy), FRONT END (brief for a build agent)

Read `COMMON-RULES.md` first, then `../slices/S07b-autonomy-and-waiting-queue.md` (the decision, the contract, the "For S7c" paragraph and the deviations the backend track reported: the contract as BUILT is in that file, read it, it differs slightly from the first draft), `../slices/S07-agent-gateway-thin.md`, `../DECISIONS.md` (no duplicate screens), `../00-PROGRESS.md`. Written 4 Nov 2026 (about 03:00 IST). The backend is built and committed (hp_erp `70f536b1`); you build against its real answers, read from `app/Domain/AgentGateway/AgentPresenter.php`, `AutonomySettings.php`, `app/Http/Controllers/Api/Agent/AgentGatewayController.php` and `tests/Feature/AgentAutonomyApiTest.php` / `AgentProposalQueueTest.php` (their assertions show the exact shapes).

## Goal

The EXISTING Agent Run History screen (`components/domain/crm/agent-runs-view.tsx`, menu `/module/crm/marketing/agent-runs`) becomes three tabs, no new menu entry, no new route:
1. **Runs** (exactly as it is today: do not change what it shows or how it behaves).
2. **Waiting for you** (count in the tab): every proposal nobody has decided, across organisations: what the agent wants to do (plain words), for which organisation (link to it), the evidence it rests on, Accept and Dismiss (with an optional reason) using the EXISTING accept / dismiss endpoints, the same exactly-once and error handling the organisation panel already has (reuse its model and decision code in `lib/agent/*` and `components/domain/crm/org-agent-*.tsx`; read them first; edit them only if unavoidable and say so). A person can clear the day's proposals without opening organisations one by one.
3. **Autonomy** (administrators only: shown only when `can.change` is true; a read-only person sees the levels as text and no controls, or no tab: choose what reads honestly and say why): one row per action with its plain-English label and description, the current level as a select, the default beside it, "changed by X on D" when known, and the last 30 days' counters (proposed, applied, accepted, dismissed, failed) as the evidence for raising or lowering a level (`accepted` is INSIDE `applied`: say it so, never add them). Changing a level asks for an optional reason, saves immediately, shows the result in words and keeps the row's focus. Choosing `alone` for an action shows a plain-English warning of what it means ("the agent does this without asking, and the record is marked Agent") and needs a second deliberate click. A kill switch for the organisation ("Stop all agent actions for this organisation" / "Resume"), with the platform-wide state shown but not changeable.

## Rules that bind this track

- Words, not colour: the meaning is always in text; use the tones in `lib/agent/tones.ts` / `lib/org-accounts/tones.ts` (text-foreground on a SOLID surface, only the border takes the status colour); NO shared `Badge` / `StatusBadge`, no `text-primary|success|warning|destructive` as text, no tint surfaces: `lib/agent/contrast.test.ts` scans the agent components for exactly these (add your new files to its list and make the scan pass).
- A table that scrolls sideways sits in an `overflow-x-auto` box that is also `relative` (an `sr-only` cell widens the page otherwise: `lib/agent/mobile-width.test.ts`, found in a real browser). Check 390 px reasoning in the code; the caller runs the real browser.
- The tabs use the shared `PageTabs` scaffold the way the Organizations scopes and the Contacts Suggestions tab do (`useUrlTab`, `?scope=` or `?tab=` in the address so a refresh and a pasted link open the same tab; read how they do it). A non-administrator who pastes the Autonomy address lands on Runs.
- The backend may not be deployed everywhere: if `GET /api/agent/autonomy` or `/api/agent/proposals` answers 404, the tab says "This server does not have it yet" in words and the other tabs keep working.
- Every action: one in flight (also within one tick), late answers for another row dropped, the 403 / 404 / 409 answers say in words what happened and re-read the list, an Escape closes a reason form and returns focus. Study how `lib/facts` and `lib/agent` did these and reuse the shapes (`services/facts/facts-service.ts` keeps the refusal's whole body: do the same where `apiClient` would lose the `code`).
- Everything the model or the web wrote is text (no `dangerouslySetInnerHTML`); a link is only http(s) or a single-slash path.

## Tests and proofs (COMMON-RULES section 4 applies)

Model tests for the rules (what each count says in words, which levels need the second click, the tab visibility by right, the address round trip), whole-screen jsdom tests over a faked service (`lib/agent/fake-service.ts` is the model) including axe (`lib/agent/a11y.ts`), a mutation proof for every rule and every guard (break, see the named test fail, restore byte for byte, `cmp`), `tsc` (only the known errors elsewhere), eslint `--max-warnings=0` on your files, the whole `lib/agent` folder plus `lib/org-accounts` and `lib/facts` green, `git diff --stat components/ui` empty.

## Do not

Edit `components/ui`, any Signals / Ingestion / Partner file, the Leads files (`lead-*`, `lib/leads`, `types/crm.ts` is shared: only ADD agent-neutral types if truly needed, and prefer `services/agent/types.ts`), the Facts files (`fact-*`, `lib/facts`, `services/facts`), `contact-*`, `account-360.tsx`, `organization-*` (read only). No push, no commit, no server, never `npx`. Another build agent (S6b) works in this repo on the leads screens right now: the working tree holds its uncommitted files; never stage or touch them.

## Report (COMMON-RULES section 7)

Files added or changed, deviations with reasons, test counts and mutation results, what you could not exercise against the real backend (the caller will in a browser), what the caller must check. Keep `../slices/S07b-autonomy-and-waiting-queue.md` current (append-only work log, a S7c section).
