# Demo — Sprint 1

Ten minutes. Two terminals and a browser. Everything below has been run; the numbers are real.

## Set up

```bash
# terminal 1
cd hp_erp && php artisan serve --host=127.0.0.1 --port=8000

# terminal 2
cd g2gv0 && npm run dev
```

Mint one token per role (they are deleted afterwards):

```bash
cd hp_erp
php artisan tinker --execute="require '$PWD/Docs/hrit-audit/_evidence/mint-audit-tokens.php';"
```

---

## 1. The headline, in one command (2 min)

This is the whole sprint in one screen. It runs the **exact script** that broke the product during
the audit.

```bash
bash Docs/hrit-audit/_evidence/probe-writes.sh
```

**Before Sprint 1** — an ordinary employee, five successes:

```
employee self-approves #223                 200  Leave request updated successfully
employee deletes another user's #219        200  Leave request withdrawn successfully
employee creates leave type                 201  Leave type added successfully
employee grants itself org-wide rights      200  "scope":"Organization","approve_leave":true
```

**After** — same script, same user:

```
W1  employee applies for own leave          201  Leave applied successfully          <- still works
W2  employee self-approves                  403  You do not have permission to decide leave requests.
W3  employee deletes a colleague's          403  You can only withdraw your own leave request.
W4  employee creates a leave type           403  You do not have permission to change leave types.
W5  employee grants itself rights           403  You do not have permission to change leave role permissions.
```

**Say this:** the product already had a screen that said employees can't approve leave. Nothing read
it. Now everything does.

---

## 2. Payroll is locked on the server, not in the browser (2 min)

```bash
bash Docs/hrit-audit/_evidence/probe-sprint1.sh
```

14 assertions, all PASS. The two blocks to point at:

```
PASS  employee  -> /employee-salary-structure   403
PASS  auditor   -> /employee-salary-structure   403
PASS  recruiter -> /employee-salary-structure   403
PASS  dept_head -> /employee-salary-structure   403
PASS  rep_mgr   -> /employee-salary-structure   403
...and it must still SERVE the people who need it:
PASS  admin     -> /employee-salary-structure   200
PASS  hr_mgr    -> /payroll-type                200
```

**Say this:** before today the lock was a piece of React in the user's own browser. Five roles the
menu hides payroll from could read every salary in the company by asking the server directly — and
the reply included everyone's encrypted password. Both are closed.

---

## 3. Monthly Payroll Report opens (1 min) — visible in the UI

Sign in as **HR Manager** or **Administrator** → **HRIT Solutions → Payroll Management →
Monthly Payroll Report**.

- **Before:** the screen never loaded. `GET /monthly-payroll/create` returned
  `500 Session store not set on request.` for every role, administrators included.
- **After:** it loads, with the tenant's real employees and pay heads.

Be straight about the caveat: it takes about **28 seconds** for 122 employees. That is now filed as
**F-121** and fixed in Sprint 6 — the 500 was hiding it.

---

## 4. Everyone stops seeing everyone's leave (3 min) — visible in the UI

Open **HRIT Solutions → Leave Management → Leave Requests** as two different people.

| Signed in as | Requests visible | Why |
|---|---|---|
| An ordinary employee | **only their own** | matrix says scope `Self` |
| HR Manager / Administrator | **all 18** | matrix says scope `Organization` |

Before this sprint both saw all 18 — names, departments and reasons. Leave reasons are health and
family information.

To show it is reading configuration rather than hard-coded, open **Leave Configuration →
Roles & Access**, change **Employee** from `Self` to `Department`, save, and reload Leave Requests
as the employee. The list widens. **Change it back.**

That tab now controls something. Until today it wrote a row nothing read.

---

## 5. Roles are real names now (1 min)

Sign in as the **Auditor** or **Executive** account on tenant 3.

- **Before:** the frontend guessed your role from your job title. "Auditor" matched none of its four
  patterns, so you were treated as an ordinary Employee. "Reporting Manager" matched the word
  *manager* and was silently promoted to Department Head. Anyone who renamed a profile to contain
  the word *admin* became an administrator.
- **After:** nine real roles, taken from the identifier the backend has always authorised on.
  Auditor and Executive now see the reporting screens their role exists for.

---

## If you are asked "what is still broken?"

Answer honestly — it is in the audit:

- Leave balances are still **zero for everyone** (F-96): one entitlement row exists platform-wide
  and no screen creates them. Sprint 4.
- Leave is still charged in **calendar days** — a Saturday-to-Sunday request costs 2 (F-95). Sprint 4.
- Attendance Tracking still shows a **made-up leave balance and holiday** to every employee (F-97).
  Sprint 2.
- Monthly Payroll is **slow** (F-121) and attendance reports are still **open to every role** (F-120).

10 of 33 findings are closed. The dashboard link has the current count.
