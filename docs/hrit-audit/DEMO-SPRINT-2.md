# Demo — Sprint 2

Eight minutes. Most of this is visible in the browser, which is the point: Sprint 1 was security you
had to prove with `curl`, Sprint 2 you can see.

## Set up

```bash
cd hp_erp  && php artisan serve --host=127.0.0.1 --port=8000
cd g2gv0   && npm run dev
php artisan tinker --execute="require '$PWD/Docs/hrit-audit/_evidence/mint-audit-tokens.php';"
```

---

## 1. The dashboard stops lying (3 min) — in the browser

Sign in as an **employee** on tenant 3 → **HRIT Solutions → Attendance Management → Attendance Tracking**.

Point at four things, in order:

| Where | Before | Now |
|---|---|---|
| Calendar button, top right | **"Today, 22 Jun 2026"** — the same date forever | today's actual date |
| Employee Snapshot | **"19 Days"** — `casual 12 + earned 7`, a fixture | the employee's real remaining balance, from `/api/leave/balances` |
| Next Holiday | **"Independence Day, 15 Aug 2026"** — hardcoded | the tenant's next real holiday, or "No holiday scheduled" |
| Attendance Alerts | **four invented lines**, identical for every employee | their own missing punch-outs and late arrivals — or *"Nothing needs your attention."* |
| My Requests | **four invented counts** | their real pending/approved counts |

**Say this:** every employee in every tenant was being told they had 12 casual and 7 earned days and
that their next holiday was 15 August. Both numbers already had working endpoints. Nobody had called
them.

Open the same screen as a **second employee** to show the numbers differ. Before, they could not.

---

## 2. The shift ring is the employee's own (1 min)

The progress ring used to be drawn against `SHIFT_TOTAL_MINUTES = 510` — 8h30m for everybody.

- Sign in as **HR Manager** (user 67), who is rostered **Saturday 09:00–14:00** in the database.
  The ring reads **"of 5h 00m"** and Expected Check Out shows **14:00**.
- Sign in as an employee with no roster: it says **"No shift set"** and **"No roster configured"**
  rather than inventing one.

```bash
curl -s "http://127.0.0.1:8000/api/attendance/self-summary?token=<hr token>" | python -m json.tool
#   "shift": { "expected_in": "09:00", "expected_out": "14:00",
#              "expected_minutes": 300, "source": "roster" }
```

**Say this:** the audit said the product had no expected times anywhere. That was wrong, and it is
written up as a correction — the roster has been on `tbluser` all along, filled in for 102 of our
122 people. We were ignoring it.

---

## 3. Five buttons that did nothing now do something (1 min)

Quick Actions, left to right:

| Button | What it does now |
|---|---|
| Apply Leave | opens the Leave Requests apply drawer (reuses the existing screen, not a copy) |
| Regularize Attendance | opens the new correction drawer |
| Mark WFH | records work mode = home on today's punch |
| Download Timesheet | downloads a real CSV of the month |
| View Monthly Report | opens the attendance calendar |

Click **Download Timesheet** and open the file — it has their actual punches, with a Work Mode column.

Also click an **alert**: it opens the correction drawer already set to the day it names.

---

## 4. Attendance correction, end to end (3 min) — the new lifecycle

**As the employee:** Quick Actions → **Regularize Attendance**.

1. Pick a past day. The drawer shows what is currently recorded for it — or "Nothing is recorded for
   this day yet."
2. Enter a corrected punch-out and a reason → **Submit request**.
3. It appears under **My requests** as `pending`, with a **Withdraw** link.
4. Submit the same day again — it says *"Your pending request for this day was updated"*. One open
   request per day, so an approver never sees three versions of one morning.

**As HR:**

```bash
curl -s "http://127.0.0.1:8000/api/attendance/regularisations?token=<hr>&scope=team" | python -m json.tool
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{"token":"<hr>","status":"approved","reviewer_comment":"Verified"}' \
  "http://127.0.0.1:8000/api/attendance/regularisations/<id>/decision"
```

Then reload the employee's dashboard: **the day is corrected** and the alert has cleared.

**Be straight about this one:** the approver's *screen* is not built yet — HR reviews through the API
today, and the queue lands with the other Leave screens in Sprint 5. The employee's half is complete.

**Say this:** the correction itself has existed on the server the whole time. Nothing ever called it,
and the dashboard showed a fake "Regularization Pending (1)" for a feature that did not exist.

---

## 5. If you are asked about Monthly Payroll (1 min)

Last sprint I said it opens. It does — but fixing it revealed that it was then timing out.

```
before Sprint 2   500  60.5s  61.0s  66.1s   "Maximum execution time of 60 seconds exceeded"
after             200  58.7s  39.5s  30.8s   identical output, byte for byte
```

It was running **one database query per day, per employee** — about 3,200 for one month, to a
database on another machine at 40 ms each. That is now one query. It is reliably working and still
too slow at 31–59 seconds; the rest is Sprint 6.

---

## Where we are

**18 of 36 findings closed (50%). 3 of 9 sprints.** No sub-module is green yet — a sub-module goes
green when its whole lifecycle works, not when one screen does.

Still open on this screen's neighbours: leave balances are still zero for everyone (F-96), leave is
still charged in calendar days (F-95), and Attendance Reports' Export and Print still do nothing
(F-99). Sprints 3 and 4.
