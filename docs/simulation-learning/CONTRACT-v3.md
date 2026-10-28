# CONTRACT v3: the dynamic simulation runtime (FROZEN 2026-10-28)

Read this before touching anything. It is the agreement between the five builders. Changes only by an amendment line at the bottom, agreed by the coordinator; additive only.
Plan: `PLAN-v3.md`. Runtime source: `D:\g2g-sim-runtime` (first commit `80126f6`). Executable form of sections 1-5 and 8: `src/platform/v2/` + `tests/v2-contract.test.ts` (21 tests, green).

## 1. One envelope, three extension points

`schema: "g2g.sim/2"`, then the v1 vocabulary (id, version, title, subtitle, summary, meta, knobs, model, assets, references, charts, sweepCharts) plus:

| Key | Meaning |
|---|---|
| `engine` | `"formula"` (equations; the v1 explorer), `"rigid"` (bodies and joints run by a physics adapter), `"des"` (discrete-event queueing network; the v1 network family, the Traffic Control Room) |
| `views` | `[{kind:"scene3d"}, {kind:"trajectory"}, {kind:"network"}]`, one of each at most; the first is the default; a learner switches when there are two. Each view is the v1 `view3d`, `view`, `scene` plus `kind` |
| `flow` | `{steps:[...], hints?}`; step `type` is `intro`, `play`, `predict`, `mission`, `explain`, `info`, `quiz` (see 4) |
| `world` | rigid engine only (see 3) |

Everything is data: expressions are the safe language of `src/platform/expr.ts` (no eval, no loops). Every list has a cap (`V2_LIMITS`, `EXPLORER_LIMITS`, `LIMITS`).

## 2. Upgrade law (tested on every shipped spec)

`upgradeV1ToV2` is total. `lowerV2(upgradeV1ToV2(S))` deep-equals `S` for TCR, tiny-api, Eye Lab, Projectile Lab. `checkAnySpec` accepts v1 and v2 and returns the same player shape. Stored rows are never rewritten: v1 stays valid forever; the runtime upgrades in memory. `lowerV2` handles formula and des with a canonical lesson (`intro? play? predict? mission+ explain?`); anything else (rigid, info, quiz, another order) is played by the runtime's own flow and `checkV2` says `unsupported` until the player for it exists (D). Issues from the v1 checkers are reported at v2 paths (`flow.steps.3.goals.0`, not `lesson.missions.1.goals.0`).

## 3. Physics as data (`engine: "rigid"`)

Units: metres, kilograms, seconds, degrees; Y up (the 3D views' frame). Every number is an expression over knobs, constants and derived values.
- `world.gravity`, `world.materials{id:{friction,restitution}}`, `world.bodies[]` (type dynamic|fixed|kinematic; shape ball|box|capsule|cylinder|hull(asset)|mesh(asset, fixed only); mass; position; rotation; velocity; angularVelocity; material; damping; ccd; sensor), `world.joints[]` (fixed|revolute|prismatic|spherical; anchors, axis, limits, motor), `world.forces[]` (force|torque vector; may read `t`, knobs and the previous step's recorded values; optional `when`), `world.events[]` (contact | enter-a-sensor).
- The run is computed at launch with a fixed step (`model.dt`, 0.001-0.02 s; at most 60 000 steps), then played back, exactly like a flight today; so outputs, goals, charts, hints and the proof of missions work unchanged.
- Recorded for every body `b`: `b_x b_y b_z` (m), `b_vx b_vy b_vz` (m/s), `b_speed`, `b_rx b_ry b_rz` (degrees, Euler XYZ). Outputs read `start.b_x`, `end.b_y`, `max.b_speed`, `min.b_y`; a 3D object follows a body with `position: ["b_x","b_y","b_z"]`. Each world event records `<id>.count`, `<id>.<n>.t`, `<id>.<n>.<channel>` as formula events do.
- Rigid `model` = the formula model without `state`, `ode`, `events`, `stop.crossing` (the world moves); `stop: {when}` may read recorded values.
- Determinism is a requirement: same inputs, same trajectory hash in any browser. Engine choice by merit (Rapier's deterministic build is the candidate; D verifies the package and version first).

## 4. Flow steps (formula, rigid)

| type | body |
|---|---|
| `intro` | `id`, `text?` (always the first screen; v1 always had one) |
| `play` | the v1 playground (`title,text,tips,knobs,start,cta`) |
| `predict` | the v1 prediction (`mission` names a mission step) |
| `mission` | the v1 mission (its `id` is the step id) |
| `explain` | the v1 explanation |
| `info` (new) | `title, text, figure?{asset,caption,alt}, cta?`: a page to read |
| `quiz` (new) | `title?, lead?, questions[1..5]{id,text,options,correct,why}`: graded, no simulation |

`des` keeps `intro, predict, mission, explain` plus `flow.hints`, canonical order only (E may widen it). At least one mission; step ids unique; at most 24 steps.

## 5. Assets

One registry for everything: `g2g_simulation_asset` (platform or tenant owner), private bucket, named by sha256, verified inside the sandbox, licence and credit mandatory. The Traffic Control Room's compiled-in models (`public/models`, `src/platform/catalog.ts`) become platform assets (E). `des` v2 gains optional `assets` when E lands; `catalog.ts` entries carry a `sha256`.

## 6. The package (built and read in the browser; the server never parses an archive)

```
my-simulation.zip
  manifest.json   {"schema":"g2g.sim.package/2","spec":"simulation.json","assets":[{"id","file","kind":"model|image","name","licence","credit","source"}]}
  simulation.json the spec (v1 or v2); its assets.models[].sha256 may be omitted: the Create screen fills it from the file named by the manifest `id`, and a given one must match
  assets/...      only the files the manifest names
  README.md       optional
```
Rules: at most 40 asset files; zip paths forward-slash, no `..`, no absolute path, no nested archive; a model file at most 25 MB, an image 2 MB, the package 100 MB; every asset has a licence and credit and a ticked attestation ("I may use this file under this licence"). The sample is a package.

## 7. Endpoints (hp_erp, `routes/api.php`, tenant from the token, authoring right required for writes)

| Call | Purpose | Status |
|---|---|---|
| `GET /lms/simulations/limits` | the limits as data (spec, asset, quota and this tenant's use) | new (B) |
| `POST /lms/simulations/assets` | multipart `file, licence, credit, source?, name?`; returns `{sha256, kind, mime, bytes, created}`; idempotent per owner | new (B) |
| `POST /lms/simulations` | create; accepts `g2g.sim/1` and `g2g.sim/2`; `status` draft by default from the Create screen | exists, extend (B) |
| `PUT /lms/simulations/{id}` | new version, same checks | exists, extend (B) |
| `GET /lms/simulations/{id}`, `/versions`, `/assets/{sha256}`, enable, disable, restore, DELETE, progress | | exist |

Reference integrity on create and version: every `assets.models[].sha256` must be registered and visible to the tenant (its own or platform), else 422 naming the asset. Stored `family` keeps its two values: `formula` and `rigid` store `explorer`, `des` stores `network` (no migration; enums diverge between hosts). Error body keeps the controller's `{message, problems[]}`.
Everything the screen calls is an API an agent can call later; Wave 3 adds validate, patch, prove, preview and asset search as the studio ops for both families.

## 8. Limits as data (proposed; A exports `limits.json` from the runtime constants, B mirrors it with a parity test)

Spec 1 MB, JSON depth 64; knobs 8, outputs 12, missions 6, assets 40, objects 48, steps 24, views 2/1, bodies 24, joints 16, forces 16, world events 8; physics steps 60 000. Model 25 MB, at most 300k triangles, textures at most 4096 x 4096 and 128 MB decoded in all; image 2 MB, 4096 x 4096. Tenant quota 200 files and 500 MB. Upload throttle 30 per minute per user.

## 9. Capability matrix (what an author wants, and what it takes)

| An author wants | Before v3 | After v3 |
|---|---|---|
| a new model or image | CLI only (`simulations:asset-add`) | upload in the Create screen (B, C) |
| a model that follows an equation | data (`view3d.objects` expressions) | unchanged |
| a ball, a ramp, collisions, a hinge | needs a developer (no rigid bodies) | `engine: rigid` (D) |
| another gravity, planet, surface | data (knob + derived + tables) | unchanged |
| a quiz or reading page between missions | needs a developer (`lesson.ts` phases) | `flow` `info`/`quiz` (D) |
| a different lesson order | needs a developer | flow order (formula, rigid) (D); des canonical |
| a queueing-network simulation | data, but its models are compiled in | models in the registry (E) |
| more than 8 knobs, 6 missions | cap | limits as data (A, B) |
| a new chart or control kind | needs a developer | not in this build; plugin hook later |
| a clip that plays, sound, video | not supported | not in this build |

## 10. Threat model (what a customer can upload) and the answer

| Input | Risk | Answer |
|---|---|---|
| spec JSON | size, depth, formula bombs | 1 MB, depth 64, expression and step caps (exist); the sandbox refuses a bad spec with a message |
| model (GLB) | external links, huge meshes, hostile extensions | magic bytes (exist); B adds a JSON-chunk inspector: embedded buffers only, extension allow-list, triangle and texture budgets; glTF-Validator in CI |
| image | script in SVG, huge pixels | PNG, JPEG, WebP by magic bytes only (exist), 2 MB, 4096 x 4096 |
| zip | bomb, path traversal | never parsed by the server; the browser reads it with the caps in 6 |
| a file hash of another tenant | cross-tenant read | reference integrity on save; the asset route is tenant-checked (exists) |
| volume | storage abuse | quota and throttle (8) |
| licence | uploading what we may not host | licence and credit mandatory, attestation ticked and stored |
| physics | runaway simulation | body and step caps, fixed step |

## 11. Work split (file ownership: disjoint, no worktrees)

| Agent | Owns | Delivers |
|---|---|---|
| A Contract and sample | runtime `tools/`, `tests/` (new files), `docs/`; hp_erp none; g2gv0 `public/sim-samples/` | `export-schema`, `export-limits`, test corpus (valid, invalid, adversarial), `AUTHORING.md`, the sample package |
| B Backend | hp_erp only | upload service and route, `limits` route, GLB inspector, quotas, reference integrity, v2 in `SimulationStore::parse`, PHPUnit |
| C Create screen | g2gv0 only | the Create tab on `add-simulation-dialog.tsx`, package reader, uploads, validation, draft, preview, publish |
| D Runtime core | runtime `src/platform/explorer/*`, `src/explorer/*`, `src/runtime/*` (new) | registries and `docs/runtime-plugins.md` first, flow steps, rigid engine, oracle tests |
| E Traffic Control Room | runtime `src/app/*`, `src/platform/{engine,des,flow,journey,catalog}.ts`, `src/scene/*`, `public/models` | engine and view plugins for des, models in the registry, upgrader use, studio ops for both families |

`src/platform/v2/*` and `tests/v2-contract.test.ts` are the coordinator's; ask for an amendment instead of editing. Each agent: read this file and only the files named in its brief; targeted tests only (the coordinator runs the full suite once per wave); no dev server unless the brief says; report in under 250 words; append progress lines to `00-PROGRESS.md`.

## Amendments
- 2026-10-28 (A's review): `V2_LIMITS.views` is 2 (one 3D, one flat; des has one network view). `specV2InputJsonSchema()` is the schema an author writes (defaults optional); the output schema marks defaulted keys required. `expr.ts` exports `MAX_NODES` (160) and `MAX_DEPTH` (24). A lowering failure caused by a cap (for example 7 missions) is an issue at its v2 path, not `unsupported` at path "" (D). Backend: mirror `scanJson` of `tests/corpus.test.ts` (JSON deeper than 64, duplicate keys, over 1 MB) in `SimulationStore::parse` (B, or Wave 2).
- 2026-10-28 (B and C review): a specification is at most 1,000,000 bytes (the server's own limit; "1 MB" everywhere means this). Knobs: 8 for formula and rigid, 12 for des. A model's JSON part is at most 4 MB. "Decoded" texture memory = the sum of width x height x 4 over a model's pictures, plus vertex and index bytes; triangles count once per node that uses the mesh, and per instance for EXT_mesh_gpu_instancing.
- `POST /lms/simulations/assets` requires `attested=1` (the person may use the file under the licence they named); the row's creator and time are the record, no new column. The screen already sends it.
- Ops (deploy checklist): on the app host PHP `upload_max_filesize` and `post_max_size` and the web server's body limit must allow about 26 MB; `GET /lms/simulations/limits` returns `server.uploadMaxBytes` so the screen refuses an oversize file before sending it.
- The vendored `public/sim-runtime` in g2gv0 must be rebuilt with the v2 front door before the Create screen's validation accepts `g2g.sim/2` (the sample is v2): Wave 2.
- Limits parity: `tests/limits-parity.test.ts` (runtime) compares `docs/schema/limits.json` with hp_erp's `tests/Fixtures/simulations/limits.json`; run both after any limit change.
