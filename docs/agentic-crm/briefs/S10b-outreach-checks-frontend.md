# Track S10b: the GTM Outreach screen shows each draft's checks (brief for a build agent, front end)

Read `COMMON-RULES.md` first, then `../slices/S10-outreach-gate.md` (the contract and the S10a work log), `../PLAN-USE-TODAY.md` (job J9), `../DECISIONS.md`. Written 4 Nov 2026 (about 10:55 IST). The backend is committed (hp_erp `2555a2bc`): read `app/Http/Controllers/Api/Outreach/OutreachGateController.php`, `routes/outreach_gate.php`, `tests/Feature/OutreachGateRoutesTest.php` and `OutreachMailersGateTest.php` for the exact shapes.

## Goal

On the VISIBLE outreach screen (GTM Outreach, menu row 457, `components/domain/gtm/outreach-view.tsx`; the CRM "Outreach Approvals" row 499 is hidden and gives the Admin a 403, so that screen is NOT your door), a person sees, for each draft message, whether it passes the outreach checks and, when it does not, which check failed and why in plain words, can press "Check again" after changing the text, and sees the same checks in the answer when a send attempt is refused with code `gate_blocked` (a refused attempt is also what a blocked message looks like after the scheduler deferred it). **No send, schedule or enable control is added, and none is changed**: sending is OFF and stays OFF.

Concretely: (1) a "Checks" section in each draft's detail or row expansion, fed by `GET /api/outreach/gate?table=gtm_outreach_messages&id=` (read-only evaluation that stores a check set and sends nothing; throttled at 60 a minute, so call it on open and on "Check again", never in a loop): each check as a plain sentence with Passed or Failed in WORDS (colour only helps), the failing ones first, a summary line ("3 of 8 checks failed: you cannot send this yet"); (2) the existing send path's `gate_blocked` refusal (HTTP 422, body with `checks`) shown as the same list instead of a generic error; (3) states: loading, the server does not have the gate yet (404 or 403: "The outreach checks are not available on this server yet", the screen works as before), an empty draft list, an error with Retry; (4) the claims the gate allows are managed through `GET/POST/PUT /api/outreach/claims` (list, propose, approve, retire): add a small read-only "Approved claims for this product" list under the Checks when a check says a claim is unapproved, so a person sees what IS approved; approving a claim is an administrator action: include the Propose and Approve buttons only if the route answers and the caller's rights allow it (read the controller), otherwise leave claims read-only.

## Raw material

`components/domain/gtm/outreach-view.tsx` and its neighbours in `components/domain/gtm/` (`grep -rn "outreach" components/domain/gtm services/gtm`), `services/gtm/*` (how the GTM screens call the API: add `services/outreach/gate-service.ts`), `lib/campaigns/*` and `lib/leads/*` (the pure-model, tones and screen-kit pattern to reuse), `lib/org-accounts/tones.ts` (words carry the meaning, text-foreground on a solid surface, only the border takes a status colour, no shared Badge or StatusBadge), `lib/agent/contrast.test.ts` (the scan list: add your new files).

## Tasks

1. Re-read the contract; list in your work log every field you rely on, marked optional.
2. Service and pure model `lib/outreach/model.ts` (the sentence per check key, the summary, the failing-first order, the "cannot send yet" wording, the `gate_blocked` body reader); types.
3. The Checks section, the refusal rendering, the states, the optional claims list; whole-screen jsdom tests over a faked service including axe, the contrast scan with your files added, a mutation proof for every rule and guard (break, see the named test fail, restore byte for byte, `cmp`); a source scan test that the new files contain no send, schedule or enable control (no `Send`, `Schedule`, `Enable`, no call to a send route); `tsc` (only the known errors), eslint `--max-warnings=0`, `git diff --stat components/ui` empty.
4. Reason about 390 px in the code (the list must wrap; a table inside a scroll box needs a `relative` box).

## Do not

Edit `components/ui`, `components/shell`, any Signals / Ingestion / Partner file, the CRM campaign, lead, opportunity, agent, ask or facts files, `types/crm.ts` and `services/crm/index.ts` (other agents are editing those right now; put your types in your own files), or any backend file. No push, no commit (the caller commits), no server, never `npx`. Other agents work in g2gv0 now (S11b: opportunity screens; FIX-A: lead, invoice, campaign-list and reports fixes): never stage or touch their files.

## Report (COMMON-RULES section 7)

Files added or changed, deviations with reasons, test counts and mutation results, what you could not exercise against real data (dev has no live GTM contacts and no drafts: the caller seeds a draft for the browser pass), what the caller must check. Keep `../slices/S10-outreach-gate.md` current (append-only work log, an S10b section).
