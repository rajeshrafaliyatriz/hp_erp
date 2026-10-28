# Simulation Learning v4: a runtime that looks and plays like a modern 3D product

Written 2026-10-28. Status: **BUILT and verified in the runtime, the backend tests and a real browser through the real app; the live database is NOT updated** (it needs your "go", see section 6).

## 1. The ask, and the deeper brief

The owner looked at Pendulum Lab and Ramp Lab next to the eye simulation and said the visuals, textures and physics were "introductory": they want modern, smooth, proper physics, **playable**, all of it dynamic (data, not code), real downloaded models instead of coded ones, and the runtime analysed, broken and fixed by us. The pasted template asked for a deeper (not bigger) version of the task in four parts.

**What a top professional would do that was not asked.** Measure the picture before judging it (triangles, maps, shadows, lights per scene); try to break the physics with oracles (closed forms, fast bodies, joints, tumbling) rather than trust it; look at every downloaded file before using it; test the thing a customer does with a real file (a model's size, origin, tilt, licence), not with a test fixture; keep the authoring surface data-only.

**The rawest material.** The live render state of each scene (`window.__stage3d`, three.js `renderer.info`), the physics engine's own output against closed forms, the downloaded files themselves (their vertices, not their catalogue entries), the real app with the real backend and bucket, and the owner's own screenshots.

**Methods and tools.** `tools/audit-render.mjs` (Playwright and the page's three.js state), `tests/rigid-torture.test.ts` and `tests/rigid-live.test.ts` (Rapier against closed forms), `tools/asset-sheet.*` (every candidate model photographed alone), `tools/_monkey.mjs` and `tools/_e2e-live.mjs` (a monkey, and a scripted hand), glTF-Validator for every model, the cross-browser fingerprint (`tools/determinism-compare.mjs`).

**What shows it is right, other than us.** The glTF-Validator (0 errors on all 32 library models); physics against closed-form answers; the same fingerprint `1e440cfdbadbd6` in Node, Chromium 151 and Firefox 157; the backend's own 162 simulation tests; 1,230 runtime tests; a real browser through the real app and backend (every file 200, no console error); the owner's eyes, which is why the live rollout waits.

**The prompt, rewritten to ask for exactly that.** "Audit the dynamic runtime by measuring it: for each shipped simulation report triangles, draw calls, materials and the maps they have, lights, shadows and what receives them. Break the physics on purpose with oracles: closed forms, thin walls at 600 m/s, joints, tumbling bodies through a pitch of 90 degrees, stacks, heavy-on-light, 60,000 steps. Fix what breaks in the engine, never in the test. Replace every coded-looking shape with a real downloaded model that you have looked at (licence CC0, or CC BY with a credit; real size and origin; no stretching), and let the shapes that collide come from the same files. Make free play of a physics simulation live: the learner can grab a body and throw it. Keep it all data: nothing a customer changes may need a code edit. Prove it in a real browser through the real app and backend, and report what you could not fix."

## 2. What the audit and the breaking found

| # | Found | Evidence | Fixed by |
|---|---|---|---|
| 1 | The two samples were drawn from **182** and **696** triangles (6 and 13 meshes); the Projectile Lab 5,964; no map of any kind on any model; only the ground received a shadow | `audit-render.mjs` | real models (21,000 to 42,000 triangles each), PBR maps, shadows on every placed model |
| 2 | A body that spins through a pitch of 90 degrees is drawn **153 degrees** wrong (the three Euler angles fold and the picture interpolates them) | `rigid-torture` | orientation recorded as a quaternion that never jumps; objects follow a body by `body`; now under 1 degree, 2 at a bounce |
| 3 | A contract hole: the schema offered `hull` and `mesh` shapes and the runtime refused them ("this build does not read yet"), so a real model could never be a collider | `rigid-torture` | `glb-geometry.ts` reads the file; the player waits for the files; a sliding crate matches `g(sin a - mu cos a)` within 3 % |
| 4 | No moving parts (a piston, a conveyor); no gravity scale | `rigid-torture` | `drive` on a kinematic body, `gravityScale` |
| 5 | A glTF material with no metallic factor is, by the spec, a fully rough metal: dark and dead under any light (several game-kit models) | `audit-render` | read as a painted surface |
| 6 | No control of tone, exposure, sun, fill light, environment; a fixed fill light flattened the shadows | the code | `scenes[].light` (tone aces / agx / neutral, exposure, sun, ambient, environment, azimuth, elevation) |
| 7 | A floor was one colour picture | the code | `ground`: `normal`, `rough`, `ao`, `tile`, `grid` |
| 8 | A downloaded model could not be touched up without code | the code | `view3d.materials` (colour, metalness, roughness, clearcoat, ...) |
| 9 | Physics was worked out ahead and played back: **nothing to play with** | the code | the live world (`rigid-live.ts`): Launch runs it, a drag takes hold of a body, letting go throws it; the missions keep the worked-out run |
| 10 | A model chosen by the asset agent, without anyone looking, was a gallows with a noose | the contact sheet | rejected; every model was photographed (`asset-sheet`) before use |
| 11 | The "plank" was modelled leaning 7 degrees about one axis and 6 about another; a ball rolled sideways on it | a trace of the ball | the build levels the whole model (`recipe.json` `"orient": "level"`); a flat board is a `box` collider, its picture on it |
| 12 | The 2K textures (1.4 to 3.3 MB) were over the 2 MB upload limit | the backend's limits | `build-sample-assets.mjs` shrinks each to the limit |
| 13 | A mission whose result did not depend on its knob (the cone was at a fixed place, so a steeper ramp changed nothing) | the numbers by angle | the cone stands a fixed distance beyond the plank's low end |
| 14 | The title was unreadable over a dark scene; the default cameras were cut off by the cards of the full-screen layout; the part check warned too early | screenshots in the real app | a frosted chip; cameras set for the full-screen layout; the check waits for the objects |

**Not found to be broken** (so left alone): tunnelling at 60 to 600 m/s against walls 4 to 40 mm thick; a revolute pendulum (period within 0.5 % of physics); a stack of five crates; 1000 kg on 0.1 kg; energy of a perfectly bouncy ball (3 % over ten bounces); a monkey (about 260 random actions on both samples: no page error); 400 worlds made and freed (no memory growth).

## 3. What changed in the contract (all additive and optional; old specifications run unchanged)

`world.bodies[]`: `shape` `hull` and `mesh` with `scale`; `drive`; `gravityScale`. Recorded channels: `b_qx b_qy b_qz b_qw`. `view3d.objects[]`: `body`, `offset`. `view3d.scenes[]`: `ground.normal/rough/ao/normalScale/tile/grid`, `light`. `view3d.materials[]`. The limits did not change (so the backend needed no change; its 162 simulation tests pass). `docs/AUTHORING.md` and the JSON Schema are regenerated; the pinned fingerprint changed (more channels) and is equal in Node, Chromium and Firefox.

## 4. The two samples now

**Pendulum Lab** (equations, as before): a retort stand and a baseball on a string on a workshop table, a real wooden floor, a studio sky; the lesson re-scaled to the bench (0.1 to 0.42 m strings; missions 1.0 s on Earth, 3 s on the Moon). **Ramp Lab** (physics): a plank on a steel drum, a baseball, a traffic cone, a chest, a concrete floor; the cone and the chest collide by the shapes of their own models; free play is live. Five of the six physics models and every picture are CC0; the plank is CC BY 3.0 (Justin Randall), its credit travels with it.

## 5. What is not done, and why

- **Pendulum Lab is not "live"**: it is the equations sample (one per engine is what a customer needs to see). Making it a rigid pendulum with a draggable ball is a small follow-up if you want it.
- **The worked-out run is on the page's main thread**: a 24-body world for 6 s takes about 1.2 s on a quiet machine, so Launch in a mission can stall the page that long for a big world (small ones take milliseconds). A worker is the fix; not built.
- **No ambient occlusion or bloom; the sky is a photograph (LDR), not an HDR file**: a new asset kind and backend work; the lighting controls and the shadows carry most of the look.
- **The body limit is still 24** (a bigger world, such as Jenga, needs the backend's limit raised too).
- **Not run on a GPU**: every picture and timing here is from a software renderer; frame rates on real hardware are expected to be far better, not measured.

## 6. What needs you

1. **Your "go" for the live database** (organisation 7 now has Pendulum Lab id 6 and Ramp Lab id 7 at version 1): register the 17 new files and add version 2 of each, as in `LIVE-ROLLOUT.md` (fourth rollout, prepared, **not run**).
2. Push and deploy hp_erp (no migration) and g2gv0 (the new runtime and samples are committed locally); until g2gv0 is deployed the gallery shows the old runtime and the cover pictures give 404.
3. Look at the two simulations in the real app and say what is still short of "modern".
