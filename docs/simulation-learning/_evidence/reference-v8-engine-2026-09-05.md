# Reference added by the owner on 2026-10-26: Dilum Sanjaya's V8 engine visualisation

Source (primary, read 2026-10-26): https://x.com/DilumSanjaya/status/2096280244663775423, posted 2026-09-05 16:52 UTC, about 12.8k likes. Fetched through the public oEmbed and syndication endpoints (the same route as the Cell Architecture Studio post). The video (52 s, 1728x995 source) was downloaded to a scratchpad outside the repo and 24 frames were read; the video is not stored in any repo.

**What the post says [P]:** "Asked GPT-6 Astra to create a highly detailed, interactive visualization of a V8 engine. The result is pretty impressive." No link, no repository, no licence: patterns only, nothing to copy or reuse. The model name is the author's own claim and could not be checked (UNVERIFIED).

**What the frames show [P, my reading of 24 frames]:** a light studio-grey scene (not the cream of Learn Arduino) with near-black machined parts and red accents, glass-transparent cylinders, callout labels with thin leader lines, and an "instrument" interface:
- left column: title and spec line, one huge live number (engine speed) over a thin scale, two small linked diagrams (slider-crank, cross-plane crank) with their formula;
- right column: the current phase as a list with the active item marked (intake, compression, power, exhaust), a large live value (chamber pressure), a valve-timing chart with a "drag to scrub" cursor, an indicator (pressure-volume) diagram;
- bottom: pause, step back and forward 15 degrees, engine speed slider, playback speed slider; camera preset buttons (hero, front, side, valley, cylinder); cutaway modes (glass, section, solid); overlay toggles (callouts, charge, arrows, isolate, refract, charts); firing-order buttons 1 to 8 that click-to-track one cylinder; a hint line "drag to orbit, scroll to zoom, H hides the overlay";
- every representation (3D, charts, diagrams, phase list) is driven by one time cursor, and the view stays readable while it moves (frames show the same panels at 700, 750, 900 and 2150 rpm).

**What we take from it for Traffic Control Room (design intent, own implementation):**
1. One time cursor drives everything (3D scene, timeline chart, stage list, gauges); the chart has a scrub handle.
2. Camera presets as buttons (overview, edge, API tier, data tier, queue) instead of free orbit only; orbit and zoom stay available.
3. Overlay toggles (labels, flow arrows, load heat, isolate one stage) and an "x-ray" cutaway of the database tank to show connection slots in use.
4. Callout labels with leader lines tied to the 3D parts, switchable and hideable with a key.
5. "Follow one request": the analogue of tracking one cylinder: click a sampled request and see a waterfall of where its time went (queue, CPU, cache, database).
6. A headline live number (p95 latency against its target), a small-caps label system, thin rules, black segmented buttons with an inverted active state, one accent colour for live values (teal in our palette instead of red).
7. Playback controls: pause, step, speed.

**What we do not take:** its studio-grey and black look. The owner's target is the cream, teal and navy look measured from Learn Arduino (D4); this reference confirms the quality bar and the interaction vocabulary, not the colours.
