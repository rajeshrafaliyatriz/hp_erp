# Phase 12 — closing the four that were left open, and the one they were hiding

**Closed:** F-142, F-143, F-144, F-145 — the four Phase 10 deliberately left open.
**Raised:** **F-150** — a pay head deleted from the screen is still applied to every payslip.
**Verification:** **253 assertions across 13 probes, 0 failures.**
**Gate:** 13 of 16 lines pass. **Master sheet: 14 of 16 columns green.**

---

## Why these were open, and what changed

Phase 10 raised four findings and refused to fix them, each for a stated reason: three touched money
and one changed a frontend contract. Those reasons were put to the customer, who asked twice for the
module completed.

That is a decision, and it is taken here. But "complete it" is not the same as "decide it" — so each
fix closes the **defect** without answering the **question** that is genuinely the customer's.

| | The defect, fixed | The question, still theirs |
|---|---|---|
| F-142 | new payslips can no longer diverge | what to do with the six already filed (**Q8**) |
| F-143 | the month split cannot recur | what the eleven legacy rows meant (**Q9**) |
| F-144 | a rule proven dead is deleted | — |
| F-145 | the response is bounded in code | — |
| F-150 | a depended-on head can no longer be deleted | whether six structures should stop applying deleted heads (**Q10**) |

---

## F-142 — the posted totals are now a checksum

The fix the finding's own sketch asked for. `monthlyPayrollStore` recomputes each employee's figures
by **calling `getEmpMonthlyData`** — the same code path that drew the screen, not a second copy,
because two copies of this arithmetic would drift exactly as `getEmployeeLists` did in F-149.

```
posted 34799 / 201   (what the screen was told)   -> accepted, and the SERVER's figures are stored
posted 999999 / 0                                 -> {"status":"0","mismatches":[
                                                       {"employee_id":10,"sent_payment":999999,
                                                        "computed_payment":34799,
                                                        "sent_deduction":0,"computed_deduction":201}]}
```

**Refused, not silently corrected.** A payslip that changes when you press save without saying so is
a worse failure than one that is refused. And the six payslips already on file are untouched — which
is exactly how this closes without answering Q8.

**One implementation note, because it passed a forged payslip once.** The check builds a sub-request,
and reading `input('token')` alone left it anonymous when the caller had authenticated by
`Authorization` header — so `payrollTenantId()` returned null, no structure was found, and the
verification silently skipped. `bearerToken() ?: input('token')` is what makes it actually run. The
first test run reported success on a 999999 payslip; that is why the probe asserts the *stored value*
and not just the response.

---

## F-143, F-144, F-145 — the smaller three

**F-143.** `payrollDeductionStore` now runs the month through `Helpers::canonicalMonth()` and refuses
what it cannot name — F-137's guard, in the table F-137's repair did not reach. `month=8` is refused;
`month=Nov` stores as `Nov`. The eleven legacy rows are deliberately not repaired: `"3"` could be
March or a March-year convention.

**F-144.** The hardcoded February rule is deleted. Proven, not assumed: February now computes 34799
for employee 10, identical to November. Worth stating precisely — the branch was excluded by
`status = 1`, **not** by the soft delete, and F-150 below shows why that distinction matters.

**F-145.** Pagination is **opt-in** (`per_page`, `page`); with neither, the response is exactly what
it always was, now bounded by `MAX_ROWS = 2000` — above every organisation on the platform, so no
caller changes behaviour today. A cap that hid rows silently would be worse than none, so
`meta.truncated` says when it bites, and `meta.total` still means how many **match**.

---

## F-150 — the finding the twelfth golden transaction was hiding

Driving the mid-month joiner produced figures the wrong shape:

```
employee 10, Nov 2026   30 days -> net 34799
                        15 days -> net 34899
                         1 day  -> net 34992
```

**Net pay rises as days worked fall.** The only head that pro-rates for that employee is head 4 —
**soft-deleted on 2025-09-18 and still being applied.**

The Payroll Type *list* filters `deleted_at`. **The calculation does not** — it selects on `status`
alone, and `PayrollType` carries no `SoftDeletes` trait. So a head deleted from the screen goes on
being applied to salaries, invisibly, indefinitely.

**And the deleted heads on live are load-bearing:**

```
soft-deleted head ids: 1,2,3,4,5,12     6 of 8 live structures reference one

structure  emp tenant | net now  | net if excluded | change
20 / 25    10  3      | 34799.00 |        34999.00 |  +200.00
21         1   1      |  3500.00 |         1000.00 | -2500.00
22         2   1      |  3500.00 |         1000.00 | -2500.00
23         3   1      |  3500.00 |         1000.00 | -2500.00
```

Heads 1 and 5 are deleted **allowances** worth 1000 and 1500 that tenant 1's structures depend on.
Excluding them is a **71% pay cut** for three employees — and 3500 is exactly what their Aug-2025
payslips say, so those payslips were computed *with* the deleted heads.

**So the calculation was not changed.** Both answers move real money: leave it and an organisation
pays a head nobody can see; correct it and three salaries are cut by 71%. That is **Q10**.

**What was fixed is the cause.** `payrollDestroy` now refuses to delete a head that live salary
structures reference, naming how many:

```
DELETE head 9 (BASIC) -> "This pay head is still used by 2 salary structure(s).
                          Remove it from those structures first - deleting it here
                          would leave it silently applied to their pay."
DELETE an unreferenced head -> still works
```

The situation cannot get worse while the customer decides, and no existing salary or payslip moves.

---

## Where the module stands

| | Sprint 0 | Now |
|---|---|---|
| Release gate lines passing | 1 of 16 | **13 of 16** |
| Master-sheet columns green | 0 of 16 | **14 of 16** |
| Golden transactions | 2 of 12 | **11 of 12** |
| Probe assertions | 0 | **253, 0 failures** |
| Findings | 53 raised | **60 closed**, F-150's cause closed |

**Still AMBER, and now for only three reasons — none of them a defect left unfixed:**

- **Scale** cannot be measured where the data does not exist. No organisation on this deployment has
  more than 13 leave rows or 6 payslips. Employee count (1001) and attendance (939) *were* measured
  and are healthy.
- **Q10** puts real money either way.
- **Domain sign-off** has not been sought and cannot be given by me.

---

## A note on method

Every finding in this phase was closed with an assertion checked against the **unfixed** code. F-142's
was the important one: the first run reported a forged payslip *accepted*, which is how the
`bearerToken()` bug was caught. Had the probe asserted only the response and not the stored row, that
fix would have shipped broken and the probe would have passed.

That is the same lesson as F-109, F-149 and the vacuous Form 16 assertions — three times now in this
audit, in three different places. **Assert the state, not the answer.**
