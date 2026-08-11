# User flows — the short roles

**Written 2026-08-11, against what exists.**

Roles here get a **difference list**, not a flow. The test applied to each:

> **Does this role have a JOURNEY of its own, or the same journey with fewer
> rights?** The first needs a flow; the second needs a difference list.

A short section stating the difference honestly beats a full flow repeating
another role's routes with a narrower scope column.

---

## `hr_manager` — **the same journey, and not even fewer rights**

**Sized as a short section BEFORE writing, on a measurement.** The expectation was
"same journey, narrower scope". The measurement said something stronger.

    route guards        16 of 16 IDENTICAL (profile:admin,hr)
    menu rights         admin 81, hr 80, shared 80
    admin-only menus    1  ("Integration")
    hr-only menus       0

### THE ENTIRE DIFFERENCE IS ONE MENU

There is **no endpoint** an Administrator can reach that an HR Manager cannot, and
none the other way. Every route in `administrator.md` §0 — competency definitions,
role mapping, the 9-box, seed-library preview, framework dry-run and commit,
readiness gates, gate acknowledgement, reporting lines, performance cycles, talent
mobility, succession, terminology — is `profile:admin,hr`.

**So `administrator.md` IS the HR Manager's flow.** Read it as written, minus
"Integration".

### ⚠ THAT IS A FINDING, NOT A CONVENIENCE

**Two of the nine canonical roles are, in practice, one role.** An HR Manager can
acknowledge a readiness gate into `blocked`, commit a framework import, and rewrite
the reporting line — every irreversible or wide-reaching action the product has.

Whether that is intended is a **product decision, not a bug to fix quietly**:

- If the separation was meant to exist, the rights matrix never received it, and
  "HR Manager" is currently a second administrator with a different label.
- If it was never meant to exist, one of the two names should go, because a role
  list that implies a distinction it does not enforce misleads whoever reads it.

**It will not survive a customer's permissions review either way**, and it is
better raised now than answered under one. Promoted to `08-connection-plan.md` §1
as an addendum to the completeness diagnosis.

### What an HR Manager must never see

Identical to the Administrator's list, and enforced by the same code:

- another tenant's anything (tenant from identity on all 16 endpoints);
- a gate reaching `blocked` without an acknowledgement naming who and when;
- a `0` where nothing was measured;
- an import that resolved an ambiguity for them;
- `hpbrain_*` data.

### Where this role stands

**Works today:** everything in `administrator.md` §7's "works today" list.

**Dead-ended on:** the same five items, unchanged — no dead end is specific to HR.

**Depends on:** the same list, plus **the role-separation decision above**, which is
the only item unique to this role and is a decision rather than a build.

---

## `hr_executive`, `recruiter`, `executive`, `auditor`

**Not yet written.** They were scoped as short sections and remain open:

| role | scope agreed |
|---|---|
| `hr_executive` | say only where it differs from `hr_manager` |
| `recruiter` | four screens plus its own capability view |
| `executive` | read-only: what they see and why they cannot act |
| `auditor` | read-only: what they see and why they cannot act |

**A measurement is needed first for each**, the same one that settled
`hr_manager`: route guards and menu rights against the role they are nearest to.
Writing them from the plan rather than from that measurement is what X-17 exists
to avoid — and in `hr_manager`'s case the measurement changed the answer from
"narrower scope" to "one menu".
