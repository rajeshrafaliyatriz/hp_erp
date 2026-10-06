import io, os
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"

p = os.path.join(D, "07-gap-register.md")
t = io.open(p, encoding="utf-8").read()

# 1. R10 extension - one column is not the class
t = t.replace("| **R11** |",
"""| **R10b** | **A PROXY THAT NAMES ONE COLUMN MEASURES ONE COLUMN, NOT THE CLASS.** Before writing down any scope figure, ask: **"what else does this pattern look like under a different name?"** Where the answer is unknown, mark it **ESTIMATE PENDING** rather than guess | **G-SEC-12.** S-3 counted `created_by` and reported **33**. The real class spans `created_by`, `updated_by`, `verified_by` and `reviewer_id` — **one pattern under four names — and the true figure was 76. Low by 2.3×.** ESTIMATE PENDING was correct and should be used the same way again |
| **R11** |""")

# 3. CRLF note
t = t.replace("Applied together: R3 before counting, R1 before quoting, R2 before interpreting,",
"""> **Anchor regexes must handle `\\r\\n`.** Line endings have now defeated a pattern
> **twice** — once on a `$`-anchored trait-use match, once earlier in the phase.
> Costs nothing to guard; prevents the third.

Applied together: R3 before counting, R1 before quoting, R2 before interpreting,""")

# 2. verified_by against G-CERT-01
a = "# G-DATA-07 — `s_library_map.skill_ids` packs ids into a TEXT column"
t = t.replace(a, """## G-CERT-01 addendum — `verified_by` was caller-supplied too · ✅ **BOTH HALVES CLOSED**

**Recorded here, not only under G-SEC-12, because a reader looking at
certifications must find it.**

`CertificationController` had **two** trust defects on the same record, and they
compound:

| Half | Defect | Closed by |
|---|---|---|
| **1** | `verification_status` taken from the request — **a credential could declare itself verified** | **D-002** (`2026-08-06`) |
| **2** | `verified_by` taken from the request — **a caller could also name WHO verified it** | **D-006** (`d70a204c`) |

**Together they meant a credential could assert both that it was verified and who
signed it off.** Neither claim was checkable, and nothing looked wrong.

> **Certification trustworthiness is what a regulated customer tests first.** A
> credential that verifies itself, signed by a person who never saw it, is the
> exact artefact an auditor asks to see the provenance of.

**Both halves are now closed.** `verification_status` is server-set to `pending` on
create; `verified_by` resolves from the token via `g2gActorId()`. The legitimate
verify path still works and still stamps `verified_by` / `verified_at` — but from
identity, not from input.

⚠️ **Still open:** that verify path is **not role-gated** (G-SEC-01). Anyone who can
reach it can verify. Logged, not fixed here.

---

""" + a)
io.open(p, "w", encoding="utf-8").write(t)
print("R10b, CRLF note, G-CERT-01 addendum recorded")
