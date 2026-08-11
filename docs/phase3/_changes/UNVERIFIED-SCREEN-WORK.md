# Screen work shipped while G-UI-02 is open

**X-21 cannot reach screens while G-UI-02 is live** - the employee sidebar renders
nothing, so the harness cannot navigate to new UI. Every screen change shipped
after 2026-08-11 lands **unverified by browser**, and this is the defined set to
verify when G-UI-02 is fixed - rather than "everything since".

**AT FIVE ITEMS, G-UI-02 COMES BACK** with the new instrument (instrumentation
inside the hook: the call site, the `enabled` evaluation, the cache read). Not the
network boundary - that layer produced five eliminations and no answer.

| # | Item | Files | What a browser must confirm |
|---:|---|---|---|
| 1 | **C-10** | `library-config.ts` | `approve_status` renders in the drawer and **not** in the edit form (it is `readOnly`); `skill_maps` renders |
| 2 | **L-06** | `library-tab.tsx` | the delete dialog shows the count, its basis line "Counted by key", the breakdown, and the **failure state** when the request fails - not a zero |
| 3 | **G-UI-01** | `cm-my-capability-screen.tsx`, `content-map-m2.ts`, `index.ts` | the capability screen renders at menu 224 for all 8 granted roles; unmeasured shows "Not yet assessed" and no zero |

**3 of 5.**

## Why the cap is 5 and not a time limit

Unverified screen work compounds: each item is individually small, and the set as a
whole becomes something nobody can hold in their head. At five, verifying the
backlog costs more than fixing the blocker - so the blocker wins.

## What IS still verified on these

Tier 1 (API values) and Tier 2 (component source) run on every smoke invocation and
cover all three. **What is missing is only what a browser can see**: that the
element is visible rather than present, that the component does not throw on
render, and that the data arrives. Those are exactly the three residue items X-21
exists for - and exactly the class G-UI-02 belongs to.
