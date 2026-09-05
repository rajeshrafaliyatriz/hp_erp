# Demo — Sprint 3

Six minutes. Sign in as **HR Manager** on tenant 3 →
**HRIT Solutions → Attendance Management → Attendance Reports**.

---

## 1. The screen can produce a file (2 min)

Top right of the page there are now two buttons: **Export CSV** and **Print**.

- Pick a department and a date range, hit **Apply**, then **Export CSV**. Open the file — it is the
  rows on screen, for the filters you applied.
- Switch to the **Daily Details** tab and export again — you get the daily rows, not the grouped
  summary. The export follows what you are looking at.
- Hit **Print**: the filter bar, the tabs and the buttons drop out and the table prints as a report.

**Say this:** the audit recorded these as buttons that did nothing. When I went to fix them I found
there were **no buttons at all** — the code behind them existed and was wired to nothing. That is
worse than what was written down, and it is corrected in the register.

---

## 2. A dropdown that lied is gone (1 min)

There used to be a **Saved Reports** dropdown here offering "Last Month Report", "Q1 2026 Report" and
"This Week Report". It saved nothing, and picking one did nothing.

Look at the **Quick Filters** row now: Today · This Week · This Month · **Last Month · This Quarter ·
This Year** · Custom. Click **Last Month** — the date range changes and the report reloads.

**Say this:** those three "saved reports" were date ranges, and the control right next to them is a
date-range picker. It was a broken copy of a working control. So it went, and the ranges it promised
were added to the one that works. Building a save-your-report feature to justify it would have been
a bigger version of the same mistake.

---

## 3. The row action opens something (30 sec)

**Daily Details** tab → click the eye icon on any row. A drawer opens with that person's day.

That drawer was already built, sitting in the codebase, rendered by nothing. The button had no
handler at all.

---

## 4. Reports are private now (2 min)

```bash
bash Docs/hrit-audit/_evidence/probe-sprint3.sh
```

16 assertions, all PASS. Three blocks to point at:

```
403  employee / recruiter / department_head   ->  the attendance report routes
200  hr_manager / administrator / auditor / executive  ->  the same routes
200  employee  ->  my-attendance, self-summary, regularisations, the punch screen
```

**Say this three ways:**

1. An ordinary employee, a recruiter or a department head could read the whole organisation's
   attendance. They can't now.
2. Auditors and executives still can — that is what those roles are for. Hiding the reports from the
   two read-only oversight roles would remove the only thing they exist to look at.
3. Employees can still clock in and out. I re-tested that on purpose: **a lock that also stops people
   working is not a fix**, and the punch path is how everyone records a working day.

It is locked on **both** doors — the old screens and the new API the React page reads. Locking one
would have moved the hole, not closed it.

---

## 5. The filters really filter (30 sec)

Not "the dropdown changes" — the query changes:

| Filter | Employees returned |
|---|---|
| All departments | **118** |
| Nursing | **14** |
| Administrative Support | **9** |
| One employee | **1** |

---

## If you are asked what went wrong

Be straight, because it is in the documents anyway:

**I marked a finding closed last sprint that wasn't.** It covered twelve dead buttons across three
screens. I fixed the six on the screen I was working on and ticked off the whole finding. Four more
are fixed now; two remain, on the Leave screens, in Sprint 5. The numbers were revised down (18 → 17
closed) and the correction is written into the audit rather than quietly patched.

That is the same failure mode the audit was commissioned to find — something that looks complete and
isn't. Worth saying out loud, because the value of this exercise is that the report is trustworthy.

---

## Where we are

**20 of 36 findings closed (56%). 4 of 9 sprints.** No sub-module is green yet.

**Next is the big one — Sprint 4.** Every leave balance in the product is currently **zero**, because
the entitlement table has one row for the entire platform and no screen creates them. And leave is
charged in **calendar days**, so a Saturday-to-Sunday request costs an employee two days. Those are
the two findings that make Leave Management unusable as an HR system.
