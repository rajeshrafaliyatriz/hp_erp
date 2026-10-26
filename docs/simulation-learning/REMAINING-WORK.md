# Simulation Learning: what is still remaining

Written 2026-10-26, at the end of the first full day of work. Read `00-PROGRESS.md` for what was done and `DECISIONS.md` for why. This file is only the open items, in plain words.
Size: S = under half a day, M = 1 to 3 days, L = a week or more. Who: **Owner** = needs your decision or action, **Dev** = needs building.

## Done (one paragraph)

The Traffic Control Room simulation, the generic player (simulations are data), G2G colours, the per-organisation database and API (40 tests), the menu entry and
gallery screen in the LMS, safe player with progress and resume, add from the G2G library or by import, the live database (db3) for organisation 6, tests, review
and docs. Both repos are pushed (hp_erp `Milan-2`, g2gv0 `milan1`).

## 1. Needs you (decisions and actions)

| # | Item | Why it matters | Who |
|---|---|---|---|
| 1 | Merge the two branches to main and deploy hp_erp and g2gv0 | Until then production shows organisation 6 the new menu entry and opens a placeholder (hide it with `status = 0` on row 481, see `LIVE-ROLLOUT.md`) | Owner |
| 2 | Create a Git repo for the player source `D:\g2g-sim-runtime` and push it | It has no remote and no commit; the built player is already inside g2gv0, but the source lives only on this PC | Owner |
| 3 | Regenerate the Sketchfab token and the Tripo key that were pasted in chat | A key shown in a chat should be treated as exposed; Tripo's also returned 401 | Owner |
| 4 | Decide: pay PhET ($10,000) or not; allow CC BY models (the Traffic Control Room already uses some, with a credits screen); any GPL component | The plan stops at these | Owner |
| 5 | Name the subject experts, the lowest device to support, and whether to run the legal check on CERT-In, POSH and DPDP duties | Needed for sign-off and for the human tests below | Owner |
| 6 | Send the X link for the Forge AI demo and a Web Analytics export (device, OS, browser) for g2gv0 | Step 0 still says "unverified" for Forge AI; the export decides the lowest device | Owner |
| 7 | Decide whether other organisations get Simulation Learning (`simulations:grant --tenant=N`), and whether the Traffic Control Room becomes a platform simulation for all (`simulations:import --platform`) | Today only organisation 6 has it, and the "G2G library" tab in the add dialog is empty | Owner |
| 8 | Decide whether the simulation should also be a lesson inside course 201 | The first plan put it in a course; the gallery is a separate screen. The earlier lesson upload (`publish/PUBLISH.md`) was blocked and is now optional | Owner |

## 2. From the plan, not built yet

| # | Item | What it is | Size | Who |
|---|---|---|---|---|
| 9 | M5: studio (author's workbench) | A local library to add, version, export and delete simulations, plus an MCP server so an AI agent can use it. The LMS tables now cover storage, so this is mainly the author-facing tool | L | Dev |
| 10 | M6: AI authoring | Generate a simulation from a prompt with validate-and-retry; edit a running one by prompt as a checked patch; one call that proves a simulation is solvable. Needs a server-side AI gateway with a per-tenant limit | L | Dev |
| 11 | M7: second simulation made through the agent | Proves "dynamic": a different simulation, made by the agent, stored, played and deleted | M | Dev |
| 12 | Server-side scoring | Progress is what the browser reports; nothing re-checks it. `tools/verify-attempt.mjs` exists in the runtime repo but is not connected to the LMS | M | Dev |
| 13 | Events and competencies | The player already sends events; the LMS ignores them. Store them (the `g2g_event` table has idempotency keys) and map completion to the competency ids in the specification | M | Dev |
| 14 | Other simulation families | Only the "network" family exists. The plan also lists parameter explorers (model evaluation), branching scenarios (incident, CERT-In), Git and SQL sandboxes, and AI role-play | L each | Dev |
| 15 | Next topics (waves) | Wave 1: Model Evaluation (C427), ML Pipeline (C426). Wave 2: Git (C441, no course yet), Testing (C444). Wave 3: secure coding, communication, requirements | L each | Dev |
| 16 | M9: final quality pass | Re-run everything on a clean machine and a low-end laptop | S | Dev |

## 3. Human proof steps from the plan (no tool can do these)

| # | Item | Who |
|---|---|---|
| 17 | A named expert signs the Traffic Control Room content and the 3D labels | Owner, expert |
| 18 | Test with a real screen reader (NVDA) and a real low-end laptop. Tools (axe, keyboard script) found 0 issues, but that is not the same | Owner, tester |
| 19 | Blind visual comparison: 5 or more outside people compare our screens with the reference videos | Owner |
| 20 | Pilot in organisation 6 with a reading-only comparison group, an unseen task and kill criteria set first. With 6 to 11 people only a very large effect can show | Owner |
| 21 | About 5 think-aloud sessions with real employees per simulation | Owner |

## 4. Known small issues (all minor)

- **Performance gates** (runtime): editing latency in the 2D view is 560 ms against a 200 ms goal under software rendering; first useful view is 2.8 to 3.4 s against 3.0 s. The LCP and draw-call gates in `qa-perf` cannot fail as written and must be rewritten. Size: S.
- **Backend (noted by the reviewer, not changed):** `lmsRoleMatches` has the same "Administrator with empty role_key" gap in 17 places in 10 other LMS controllers (shared code, not ours); `update()` can answer 422 after a new version was already saved; a progress `result` over 4,000 characters is cut and reads back as nothing; the `include=all` note in the list docblock does nothing. Size: S.
- **Runtime:** a host's resume snapshot loses to one already stored in the same browser (not an issue inside the LMS frame, which has no storage); the player posts to any origin if neither `?from=` nor a referrer is given. Size: S.
- **g2gv0:** one older type error in `learning-delivery-workspace.tsx` line 1113 (`certificate_available`), not ours. The browser checks that proved the screen (`tools/_gallery.mjs`, `_gallery2.mjs` in the runtime repo) are temporary scripts with this PC's paths; g2gv0 has no Playwright, so they should become a proper end-to-end suite. Size: M.
- **Housekeeping:** the runtime repo's `shots/` folder still holds old cream-look screenshots; the vendored player in g2gv0 must be refreshed after every runtime change (`node tools/vendor.mjs <g2gv0>`); the dev host has no leftover test data (cleaned).
- **Security hardening for later:** serve the player from its own cookie-less domain, add a Content-Security-Policy to g2gv0 (it has none), rate-limit the progress endpoint. Size: M.

## 5. Where things are

Code: hp_erp `Milan-2` (`4fa7972c`), g2gv0 `milan1` (`96cb3c5`), player source `D:\g2g-sim-runtime` (local only). Live record: `LIVE-ROLLOUT.md`. Decisions: `DECISIONS.md` (D1 to D46).
