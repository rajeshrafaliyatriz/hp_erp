# X-21 — real browser verification

    node verify.js            # starts and stops Laravel :8000 and Next :3000 itself
    node verify.js --attach   # servers already running

Chromium 151, headless, driving the real app against the real API.
**Separate command — NOT in the smoke suite:** two servers plus a browser is
~3–5 minutes against smoke's 91 seconds.

## Setup (once)

    npm install playwright && npx playwright install chromium

`node_modules/` is gitignored; the harness is the two files.

## What it proves that no source assertion can

| Residue item | Check |
|---|---|
| 1 — present but invisible | `isVisible()` honours display, visibility, opacity, zero size, off-screen |
| 2 — component throws on render | `pageerror` collected per login; a throwing component renders nothing and looks like a styling bug |
| 3 — API shape mismatch / no data behind correct source | the bell must REQUEST, and what is on screen must match WHAT THE API RETURNED |
| 4 — do the logins log in | all nine, through the real form |
| 5 — does navigation navigate | clicks a nav item that is not the current page |
| 6 — hydration mismatch | console errors filtered for hydration |
| 9 — interaction | the bell menu opens on click |

## The known-negative

`verify.js` runs a synthetic page carrying **the dead bell exactly as it was** —
hardcoded "New" badge, permanent "You're all caught up", no fetch — and asserts
the harness FAILS it. **A harness that cannot fail on a known-broken page proves
nothing when it passes on a real one.** R16 inverted.

## Not covered

- WCAG contrast ratios. The legibility check is a floor, not an audit.
- Anything behind a role the seed gave no data to.
