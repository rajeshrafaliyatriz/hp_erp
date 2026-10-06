# C9 — Gate C pace report

**Honest numbers, not encouraging ones.** Requested after three competency
sub-modules; produced after two write-ups plus both calibrations, because the
calibrations consumed the third slot and pretending otherwise would defeat the
purpose of the exercise.

---

## 1. What has actually been done

| | Count |
|---|---:|
| Sub-modules in the checklist | **32** |
| Sub-modules **written up** | **2** — Libraries & Taxonomy, Competency Library |
| Sub-modules **calibrated but not written up** | 1 — Development & Career Path (C1b) |
| Raw inventory elements, total | **3,378** |
| Elements **audited** | **254** (176 + 78) |
| **Coverage** | **7.5% of elements · 6.3% of sub-modules** |

### Turns spent

| Purpose | My responses |
|---|---:|
| C1b hard-unit calibration + C6/C7 + write-up 1 | 1 |
| Corrections round (L-14, C6b, §5.1 reconciliation) | 1 |
| Q-L1 marking, L-09 investigation, acceptance tests | 1 |
| AT-L03 decision, R4b, **write-up 2** | 1 |
| **Total on Gate C so far** | **4** |

**Of 4 turns, 2 produced a write-up.** The other 2 were correction and decision
rounds. That ratio is the single most important number here and the projection
below is built on it, not on the optimistic one.

---

## 2. Projection

### The honest unit cost

| Measure | Value |
|---|---:|
| Write-ups delivered | 2 |
| Turns consumed | 4 |
| **Turns per sub-module, observed** | **2.0** |

Two caveats, in opposite directions:

- **Downward pressure:** the two correction rounds produced *standing rules* (C6b, §5.1, R4b, the cost tiers, the C8 format). Those were one-off investments. Later units inherit them.
- **Upward pressure:** these were the **two best-understood** sub-modules in the product. They were front-loaded deliberately (C2), they had the cleanest source, and one was the calibration unit. `performance-center.tsx` is **6,670 lines** — 2.6× the hard calibration unit.

I do **not** think these cancel. The rules are genuinely reusable, but the
remaining units are genuinely harder, and several (Performance, Mobility &
Succession, Recruitment) have more elements than both audited units combined.

### The number

**30 sub-modules remain. At 2.0 turns each, that is ~60 turns.** Allowing the
inherited rules to save perhaps 25% on the mechanical parts, and the larger units
to cost more, the realistic band is **45–70 turns.**

**Gate C will run long.** That is the answer, without softening.

---

## 3. Scope-trim recommendation

**Trim by DEPTH, not by dropping sub-modules** — matching the stated preference,
and I agree with it on the evidence: the two units audited so far both produced a
**confirmed break** that a shallow pass would still have caught. D1 (roles invisible
to HR) and the four approval bypasses were found by reading a form config and a
controller, not by exhaustive element enumeration.

### The two depths

| | **FULL** | **SHALLOW** |
|---|---|---|
| Every element enumerated | yes | **no** |
| Confirmed breaks (a writes X, b reads Y) | yes | **yes** |
| Dead / unreachable UI | yes | **yes** |
| Free text where a key belongs | yes | **yes** |
| Security & workflow bypasses | yes | **yes** |
| Per-field consumer sweep | yes | no |
| Every button, tab, filter, column catalogued | yes | no |
| §5.1 reconciliation | yes | **yes** |
| Estimated turns | 2.0 | **0.75** |

A shallow pass keeps everything that has produced a finding so far and drops the
enumeration, which has produced **none** — the 24-field consumer sweep confirmed 24
notes and generated 10 connections only after you marked them, and the raw inventory
already holds the enumeration if it is ever wanted.

### Proposed allocation

| Depth | Sub-modules | Turns |
|---|---|---:|
| **FULL** | The remaining 8 Competency units, Employee Directory, LMS ×3, Task ×2 — **14** | ~28 |
| **SHALLOW** | Talent ×7, Organizational ×4, HRIT, Agentic, CRM, Reports — **16** | ~12 |
| | **30** | **~40** |

That is a **third off** the mid estimate, and the C2 ordering already front-loads
the FULL ones, so the trim falls naturally on the later units exactly as
anticipated.

**Not recommended:** dropping Talent entirely. Performance Reviews and Mobility &
Succession are golden threads 6 and 7 — a shallow pass that catches their confirmed
breaks is worth far more than no pass.

---

## 4. What I need from you

**Approve or adjust the FULL/SHALLOW split above.** Nothing else is blocked; I will
continue at FULL depth in C2 order until told otherwise.

One judgement worth flagging: **Employee Directory is on the FULL list** despite
being an Organizational sub-module, because §5.1 of the brief names its Competency
Mapping section as a key unknown. If that is wrong, it is the cheapest row to move.
