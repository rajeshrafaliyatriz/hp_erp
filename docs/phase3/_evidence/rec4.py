import io, os
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"

p = os.path.join(D, "07-gap-register.md")
t = io.open(p, encoding="utf-8").read()

anchor = "## G-OPS-01 — the trait behind both shipped security fixes was untracked"
new = """## G-SEC-12 — caller-supplied audit provenance · **S1** (C40)

**`created_by` / `updated_by` taken from the request body.** S-3 found the pattern
**33 times**; `PayrollController` lines 167 and 238 are two, now fixed.

### Why this is a different class from the tenant leaks

> **A leak exposes data. This CORRUPTS THE RECORD OF WHO DID WHAT** — the evidence
> you would rely on when investigating a leak.
>
> A caller can attribute their own write to another user, and the audit trail
> records it **as fact**. Nothing looks wrong.

### ⛔ THIS BLOCKS THE EVENT STORE

`05-data-flow-contracts.md` assumes `actor_id` on every event is trustworthy. **If
actor identity can be caller-supplied anywhere, the event store inherits a
corrupted audit trail on day one — the exact thing it exists to provide.**

**Sequenced BEFORE the event store in the Gate D order.** Recorded in both
documents.

### Required

1. **The complete verified list (R6).** 33 candidates, hand-classified **IDENTITY** (must come from the token) vs **SUBJECT** (legitimately supplied — *"generate this employee's payslip"*). Same method that worked on PayrollController: read each site, trace what the value feeds.
2. **Fix shape:** mirror `payrollActorId()` — token first, session fallback, **never request input**.
3. Not started. The 33 are candidates, **not** findings.

---

## G-MAP-01 — the "one-line fix" does not exist · **re-costed**

Checked before changing anything. `QuickCreateKind`
(`services/competency/command-center.ts:111`) has **five** kinds —
`competency`, `framework`, `assessment`, `certification`, `development-plan` —
and `CREATE_ENDPOINTS` has the matching five. **There is no `role-mapping` kind,
and no create endpoint for it.**

So *"point the button at the right handler"* is impossible: **there is no handler
to point at.** That is the same finding from the other side — role mapping has no
create path, and the button is bound to `framework` because that is the only thing
available.

**The only genuine one-line change is DELETING the button**, which is a
user-facing removal and needs explicit approval plus an R8 checklist. **Not done.**

**M-03 stands as S–M**, and its real content is confirmed: build the create path
(surfacing `SchoolSetupController.php:392-408`'s existing bulk insert), then wire
the button to it.

---

"""
t = t.replace(anchor, new + anchor, 1)

# C41 exposure line inside G-OPS-01
t = t.replace(
    "**Exposure is reduced, not removed.** A lost machine or a bad reset still takes\nPhase 3 with it.",
    "### C41 — the same exposure, twenty times the size\n\n"
    "**75 modified files sit uncommitted in `hp_erp`'s working tree** — the Phase 1/2\n"
    "work. An untracked trait was one accident from silently reverting two shipped\n"
    "security fixes; **75 uncommitted files in the same working tree is that exposure\n"
    "at twenty times the scale.**\n\n"
    "**They touch security artefacts.** The modified set includes\n"
    "`ApprovalController`, `CertificationController`, `CompetencyController`,\n"
    "`LmsGovernanceController`, `AJAXController`, `JobroleApiController` and the\n"
    "`Resolves*Context` traits — i.e. **the F-01 tenant-resolution work itself**.\n\n"
    "**Not mine to commit.** Raised so it can go to whoever owns them.\n\n"
    "**Exposure is reduced, not removed.** A lost machine or a bad reset still takes\nPhase 3 with it.")
io.open(p, "w", encoding="utf-8").write(t)

# Gate D ordering: G-SEC-12 before the event store
p = os.path.join(D, "00-progress.md")
t = io.open(p, encoding="utf-8").read()
t = t.replace(
    "| 11 | Event store + projector/reactor split + `task_status_history` | Not started |",
    "| 10b | **G-SEC-12 — caller-supplied audit provenance (33 candidates)** | **Not started. BLOCKS item 11** — the event store assumes `actor_id` is trustworthy |\n"
    "| 11 | Event store + projector/reactor split + `task_status_history` | Not started. **Blocked by 10b** |")
io.open(p, "w", encoding="utf-8").write(t)
print("C40 + C41 recorded; Gate D resequenced")
