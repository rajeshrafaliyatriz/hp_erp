# Dynamic Simulation Runtime for the G2G LMS: deeper brief (v3)

STATUS: draft for approval. Read-only checks done on 28 Oct 2026 (SimulationStore, the 11 routes, the Add dialog, the runtime's spec, engine, lesson flow and studio server). Nothing is built. The v2 plan this replaces is saved in `hp_erp\docs\simulation-learning\` (00-PROGRESS, DECISIONS D1-D60); this file is only about the new ask.

## Context

Today a new simulation still needs a developer. This week alone, content wishes forced runtime code changes: a full-page 3D layout, click-a-part cards, a "keep the view while turning" fix, new spec fields (`scenes.ground`, `parts.text`, `lesson.playground`). Each of those is a hard-coded boundary. The owner wants the opposite: one runtime environment (a blank canvas) that plays any simulation (3D models, 2D, steps, physics, assets) from data held in the database and bucket, so a customer downloads one sample, edits it, uploads it, previews it and publishes it into Simulation Learning without a developer. AI and form-based generation are not in this build, but everything the screen does must be an API an agent can call later.

## 1. Where it stands (verified in the code)

| Layer | Already data | Still needs a developer |
|---|---|---|
| Content | spec JSON per version, per tenant, in `g2g_simulation_version`; formulas, knobs, missions, hints, 3D parts/objects/scenes/cameras/tour/markers; assets named by sha256 | caps: 8 knobs, 8 state, 12 outputs, 6 missions, 40 assets, 48 objects (`EXPLORER_LIMITS`, spec.ts) |
| Lesson flow | the lesson's text and goals | the phase machine is fixed in `src/explorer/lesson.ts`: intro, free play, predict, missions, explain. No quiz, info or branch step |
| Physics | ODE + RK4 + events (bounce tables) in `src/platform/explorer/engine.ts` | no rigid bodies, collisions or joints; no physics library in package.json |
| Widgets and 2D | knobs, results, charts, markers | their kinds are fixed in code; the 2D stage only draws "a body moving in time" |
| Assets | explorer: DB registry + private bucket, tenant-checked, sha256-verified inside the sandbox | Traffic Control Room models are compiled into the bundle (`public/models`, `src/platform/catalog.ts`); a tenant can only add a file through the CLI `simulations:asset-add` |
| Backend | CRUD, versions, enable/disable, progress, asset route (`routes/api.php` 1339-1362) | `SimulationStore::parse` checks only the coarse shape (family, counts, meta) and leaves "every reference and expression" to the browser; JSON Schema is exported for the network family only (`specJsonSchema`, spec.ts:621) |
| Frontend | gallery, detail, sandboxed player, Add dialog with Library and Import tabs (paste or choose a JSON file up to 1 MB, vetted by the runtime itself) | no asset upload, no sample, no package, no preview before publish |
| Agent surface | `src/server` studio: guide, validate, patch, prove, store, ai (HTTP and MCP shells) | network family only |
| Source control | | `D:\g2g-sim-runtime` is `git init`ed with zero commits: the only copy of the runtime |

## 2. The four answers (deeper, not bigger)

**What a top professional would do that you did not ask for**
1. Draw the code/data boundary first: a capability matrix of everything an author might want (a model, a moving ramp, a quiz step, another gravity, a chart, a control, a physics rule, an animation clip), each marked data-only or needs-a-developer, with file and line. The hard-coded points it finds become registries.
2. Threat-model what customers upload (a spec, images, models, a zip): zip-slip and bombs, a model that links to an outside file, ten million triangles, SVG as an image, formula bombs, another tenant's file hash, quotas, a licence and credit for every asset.
3. Treat the spec as a versioned public contract: one schema for every family, upgrade functions between versions, and a compatibility corpus (Traffic Control Room, Eye Lab, Projectile Lab, the sample) that must replay unchanged on every runtime build.
4. Determinism and budgets: fixed-step physics, seeded randomness, trajectory hashes; per-device budgets (triangles, draw calls, texture MB) reported at upload.
5. Make authoring a product: plain-language errors with the path and the fix, preview before publish, rollback, "fork a platform simulation". Headline metric: time for a non-developer to publish the first simulation.
6. Build the agent interface now as the very API the screen uses, so the later orchestration is a client, not a rewrite.
7. Own the source: commit the runtime, and make CI build, test and vendor it.

**The rawest material to start from (not the earlier summaries)**
- The code as it runs: `SimulationStore::parse`, the explorer spec/engine/lesson, `host.ts` (the message contract), `add-simulation-dialog.tsx`, `src/server`, the three shipped specs (140 KB of what authors had to write), and `SHOW COLUMNS` of the `g2g_simulation*` tables on both hosts.
- The record of friction: every change in the last two days (DECISIONS D47-D60) that needed runtime code for a content wish.
- Real authors: three non-developers building from the sample, thinking aloud; and a fresh model given only the schema and tool list (what it gets wrong).
- Primary sources, to verify when used: glTF 2.0 and its physics extensions (KHR/OMI), Rapier's docs and determinism notes, JSON Schema 2020-12, OWASP file-upload cheat sheet.

**Specific methods and tools**
- Schema-first: zod 4 `z.toJSONSchema` as the one source, checked by the browser (zod), Node (studio) and PHP, with one shared valid/invalid corpus and a differential test.
- Data model: entities, components and systems with registries; lesson steps as a typed list (old specs compile to it); XState-style statechart as data for step logic, never scripts. The Traffic Control Room moves by the strangler pattern: wrap it as plugins, keep its code and tests, prove it by replay.
- Physics as data: a `world` block (bodies, shapes, materials, joints, forces) run by a lazy-loaded rigid-body adapter (Rapier, Apache-2.0; its deterministic build, package name to verify). The run is precomputed at launch like today's flights, so missions, charts and proofs work unchanged. Formulas stay the oracle: range, pendulum period, incline acceleration.
- Upload pipeline: the browser unzips (fflate) and sends files one at a time, so the server never parses an archive; per file: size caps, magic-byte sniffing (exists), a GLB JSON-chunk check (embedded buffers only, extension allow-list, triangle and texture budgets), glTF-Validator in CI and for library assets.
- Testing: vitest golden traces, fast-check on the expression engine and validator, Playwright (SwiftShader) with axe-core, PHPUnit, a fuzz corpus.
- Parallelism: contract freeze, then disjoint file ownership, then one integration wave.

**What shows it is right, other than you or me**
1. Three non-developers each make and publish a new simulation from the downloaded sample, with no code edit and no help (timed).
2. Independent validators agree: the three schema checks on one corpus; glTF-Validator 0 errors on every uploaded model; axe-core 0 serious; a performance budget on a throttled profile.
3. Physics oracles: closed-form answers within tolerance; the same inputs give the same trajectory hash in two browsers.
4. The compatibility corpus replays with identical traces and screenshots.
5. An adversarial pack (zip bomb, zip-slip, model with an outside link, 10M triangles, SVG image, formula bomb, another tenant's file, oversized spec) is refused with the right message, judged by a reviewer that did not write the code.
6. A fresh model, given only the schema, the tools and the sample, builds a valid playable simulation within three repair rounds (first-time-valid rate recorded).

## 3. Recommended build (fast, parallel, medium tokens)

Decision (owner, 28 Oct): everything on one runtime now, including the Traffic Control Room. The shell is already shared (`src/shell`: rail, side panels, dock, camera bar, HUD); what differs is the family switch in `src/app/Shell.tsx` and two separate lesson flows. So the runtime becomes one shell with three plugin registries (model engines: formula, rigid-body, discrete-event; views: 3D scene, trajectory, network diagram; lesson steps), and the Traffic Control Room is re-expressed as the discrete-event engine plus the network view. It is wrapped, not rewritten (strangler pattern): its code, tests and QA scripts stay, and it must replay identically.

**Wave 0, me, small:** commit the runtime to git (first). Freeze the contract: one envelope (`g2g.sim/2`) with `engine`, `views` and `flow`, the three plugin interfaces, the JSON Schema for both families, the package format (`manifest.json` + `simulation.json` + `assets/`, with licence and credit per asset), the endpoint list, limits as data, the upgrade rule. Run the capability matrix and the threat model.

**Wave 1, five agents in parallel (E starts once D's interfaces land), disjoint files, no worktrees, targeted tests only, each reports in under 250 words:**
- **A. Contract and sample.** New `src/platform/explorer/schema.ts` (export only; spec.ts untouched), corpus of valid/invalid/adversarial files, `AUTHORING.md` generated from the schema, the sample package (CC0 assets only; formula model, 3D parts, free play, a mission, an image; the rigid-body ramp is added in Wave 2). Files: runtime `tools/`, `tests/`, `docs/`; `g2gv0/public/sim-samples/`.
- **B. Backend (hp_erp).** An asset upload service extracted from `SimulationAssetAdd`, `POST /lms/simulations/assets` (multipart, licence, credit, source, attestation), per-tenant quotas computed from existing rows, a GLB inspector, reference-integrity on save (every named hash registered and owned), PHPUnit. No new migration expected.
- **C. Create screen (g2gv0).** A third tab on `add-simulation-dialog.tsx`: download sample, drop a package (fflate), review a licence/credit table, upload assets, validate with the runtime's `?validate=1`, save as draft, preview in the sandbox, publish. Plain-language issue list.
- **D. Runtime core (explorer side).** The engine, view and step registries and their interfaces first (published to E within the first hour), lesson steps as a typed list with a compile-from-old-keys shim, the `world` block and Rapier adapter, oracle tests.
- **E. Traffic Control Room onto the common runtime.** Register its model as the discrete-event engine and its scene and diagram as the network view; its predict/explore/explain/transfer become steps of the one flow; move its compiled-in models (`public/models`, `src/platform/catalog.ts`) into the asset registry as platform assets (licence and credit from `credits.json`, loaded by sha256 like every other file); an upgrader for `family: network`; the studio ops serve both families. Files: `src/app/*`, `src/platform/{engine,des,flow,journey,catalog}.ts`, `src/scene/*`.

**Wave 2, me plus one verifier:** end-to-end in the real g2gv0 and backend (extend `tools/_e2e-g2g.mjs` to the create flow), add the rigid-body ramp to the sample, run the adversarial pack and the agent-readiness test, vendor, push, then live (db3) updates on the owner's "go".

**Wave 3, later, designed for now:** generalise the studio ops to both families (validate, patch, prove, preview, asset search, publish) with an MCP shell; the orchestration (planner, asset curator with the licence gate, spec author, validator-repair loop, play-tester, reviewer) as a client of those tools.

**Token discipline:** at most five builders plus one fresh-context verifier in Wave 2; one-page briefs naming the files to read; no repo-wide exploration; no full-suite runs inside agents (I run the full suite once per wave); servers one at a time; progress lines go to `00-PROGRESS.md` so a cut-off agent resumes instead of restarting. Rough size: about three working sessions (the Traffic Control Room move is the extra one).

## 4. Critical files

- hp_erp: `app/Services/Lms/SimulationStore.php` (parse, create, addVersion, assetType, assetPath), `app/Console/Commands/SimulationAssetAdd.php`, `app/Http/Controllers/Api/LmsSimulationController.php`, `routes/api.php` (1339-1362), `tests/Feature/LmsSimulation*Test.php`.
- g2gv0: `components/domain/lms/simulations/add-simulation-dialog.tsx`, `services/lms/simulations.ts`, `lib/simulation-host.ts`, `public/sim-runtime/` (vendored), `public/sim-samples/` (new).
- runtime: `src/platform/explorer/{spec,engine,evaluate}.ts`, `src/explorer/{lesson,ExplorerApp,Controls,Panel}.tsx`, `src/explorer/stage3d/*`, `src/platform/spec.ts` (`specJsonSchema`), `src/server/*` (studio), `tools/{vendor,validate-models,_e2e-g2g}.mjs`; for the Traffic Control Room move also `src/app/{Shell,App}.tsx` (the family switch and the network player), `src/platform/{engine,des,flow,journey,catalog}.ts`, `src/scene/*`, `public/models/`.
- Reuse, do not rewrite: `z.toJSONSchema` at spec.ts:621, `checkExplorerSpec`, `proveMissions`, studio `ops.ts`, `SimulationStore::assetType` (magic bytes), the host protocol (`assets-needed`, `asset`, `spec`), the `?validate=1` mode.

## 5. Verification

Per wave: targeted vitest and PHPUnit by the owning agent; the full runtime suite (973 tests today) and `tsc` by me at integration. End to end: Playwright against the real g2gv0 and backend through the create flow (sample, upload, validate, preview, publish, then play and save progress). The six independent checks in section 2 are the acceptance gate. Live: assets and the spec through the same endpoints, then `simulations:import --update` only after the owner's "go"; read-only integrity check after.

## 6. Risks, and what I could not verify

- The runtime has no commits: Wave 0 fixes that before anything else.
- The Traffic Control Room move is the biggest risk: it has the most tests and QA scripts and the owner's approval. Mitigation: wrap, do not rewrite; the compatibility corpus (identical traces and screenshots, plus its existing tests and QA scripts) gates the merge; `family: network` stays accepted through the upgrader, so no database row is rewritten.
- Rapier's deterministic package name and version, and the status of the glTF physics extensions: unverified, to check in Wave 0.
- Whether the app host (202.47.117.220) has Node or can take a new composer package: unverified; the plan avoids both (the browser unzips and validates, PHP keeps shape and asset security, no archive parsing on the server).
- Deep validation in the browser is a user-experience guarantee, not a security boundary: a bad spec only breaks its own tenant's simulation, and the sandboxed runtime already refuses it with a message. The security boundary is PHP: sizes, depth, magic bytes, GLB inspection, ownership, quotas.
- Production still needs the g2gv0 and hp_erp deploy (owner's step).

## 7. The rewritten prompt (copy everything inside the box)

````text
Dynamic Simulation Runtime for the G2G LMS: brief v3

GOAL. A customer with no developer can make a new simulation (2D, 3D, steps, physics) by downloading one sample package, editing it, uploading it, previewing it and publishing it into Simulation Learning. Every part of a simulation (models, images, physics, lesson steps, controls, rules) is data held in the database and bucket, checked by the backend and played by ONE runtime; adding a simulation never needs a code change. Not now: AI or form-based generation. But every operation the screen uses must also be an API an agent can call later (validate, patch, prove, preview, search assets, publish), so the later agent orchestration is a client, not a rewrite.

MODE: plan first. Save PLAN-v3.md and update 00-PROGRESS.md in hp_erp\docs\simulation-learning; build only after I say "go"; live (db3) writes only on my "go" for that step. Standing rules: "Standing rules" in 00-PROGRESS.md and the memory index. Runtime source is D:\g2g-sim-runtime (git-initialised, zero commits: commit it first). Branches: hp_erp Milan-2, g2gv0 milan1. No tenant ids in code. 3D models are downloaded (CC0, or CC BY with a credit), never built in code. No agent worktrees. Run probes one at a time.

START FROM the raw material, not the earlier summaries: SimulationStore::parse and the 11 routes in routes/api.php; the explorer spec (src/platform/explorer/spec.ts), engine and lesson.ts; the three shipped specs; host.ts (the message contract); add-simulation-dialog.tsx; src/server (the studio); the g2g_simulation* tables on both hosts (SHOW COLUMNS); and the last two days' changes that needed runtime code for a content wish (DECISIONS D47-D60): each is a hard-coded boundary.

STEP 0. Capability matrix. For each thing an author could want (a new model, a moving ramp, a quiz step, another gravity, a new chart, a new control, a new physics rule, a clip that plays) mark: data-only today, or needs a developer, with file and line. Output: the hard-coded points to turn into registries, ranked by how often authors will hit them. Threat-model what a customer can upload (a spec, images, models, a zip) and write the limits.

STEP 1. Contract, frozen before anything is built. One versioned schema for every family, exported as JSON Schema from the code; one package format (manifest, spec, and assets with a licence and credit each); the endpoint list; the limits as data; how an old spec is upgraded (shipped specs must run unchanged). Include physics as data (bodies, shapes, materials, joints, forces run by a rigid-body adapter, chosen by merit, determinism checked) and lesson flow as data (steps as a typed list). Include the Traffic Control Room: model engines (formula, rigid-body, discrete-event), views (3D scene, trajectory, network diagram) and lesson steps are plugins of one shell; the Traffic Control Room becomes an engine plus a view on it, wrapped not rewritten, its compiled-in models moved into the asset registry.

STEP 2. Build in parallel: at most five builders (E starts when D's interfaces exist), disjoint files, one brief of under a page each, targeted tests only, reports under 250 words. (A) schema export, test corpus, authoring guide, the sample package (CC0 assets only); (B) backend: asset upload with quotas and model inspection, reference checks on save; (C) the Create screen: download sample, drop package, review licences, validate, preview, publish; (D) runtime core: engine, view and step registries, lesson steps as data, the physics adapter; (E) the Traffic Control Room as an engine and a view on it, its models moved into the asset registry. I integrate once per wave; the full suite runs only then.

STEP 3. Proof by people and tools that are not the builders, then the live update on my "go".

DONE WHEN, each by a named check that none of the builders ran: (1) three people who are not developers each make and publish a new simulation from the sample with no code edit and no help, timed; (2) the schema, the PHP check and the runtime agree on a corpus of valid and invalid files, and every uploaded model passes glTF-Validator with 0 errors; (3) physics results match closed-form answers (range, pendulum period, incline acceleration) and the same inputs give the same trajectory hash in two browsers; (4) Traffic Control Room, Eye Lab and Projectile Lab replay with identical traces and screenshots; (5) an adversarial pack (zip bomb, zip-slip, a model with an outside link or ten million triangles, an SVG as an image, a formula bomb, another tenant's file, an oversized spec) is refused with the right message, judged by a reviewer that did not write the code; (6) a fresh model given only the schema, the tool list and the sample builds a valid, playable simulation within three repair rounds (record its first-time-valid rate); (7) axe-core 0 serious issues and the performance budget on a throttled profile.

PLAN OUTPUT: the capability matrix, the frozen contract, the work split with file ownership, the order (what waits for what), a token budget per agent, and what is left for later. RULES: tag every claim verified, inferred or unverified; if something cannot be read, say so; ask me only for decisions that are mine.
````
