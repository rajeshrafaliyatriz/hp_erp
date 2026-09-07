# Reversal scripts for the HRIT sprints

One per live migration, as the audit's ground rules require: *"every live migration carries a
reversal script committed alongside it."*

**These are copies.** The originals live in `_local-backups/`, which is `.gitignore`d — that
directory also holds 3.6 MB of raw database dumps, and ignoring it is correct. Ignoring the
reversal scripts with them was not: a safety net nobody else can see is not a safety net. They are
duplicated here, next to the audit that explains what each one undoes and what it costs.

| Script | Undoes |
|---|---|
| `REVERSAL-2026-09-05-hrit-audit-probes.sql` | the five authorization holes the audit **executed** on live in Sprint 0 |
| `…-sprint1-role-backfill.sql` | the `auditor` / `recruiter` leave-role rows |
| `…-sprint2-regularisations.sql` | `hrms_attendance_regularisations` |
| `…-sprint2-work-mode.sql` | `hrms_attendances.work_mode` |
| `…-sprint4-chargeable-days.sql` | `hrms_emp_leaves.chargeable_days` and its backfill |
| `…-sprint4-allocation-decimal.sql` | `hrms_leave_allocation.value` int → decimal(6,2) |
| `…-sprint5-bad-leave-rows.sql` | the 17 soft-deleted unusable leave rows, **and** says plainly what it cannot put back |
| `…-sprint6-approval-steps.sql` | `hrms_leave_approval_steps` and its backfill |
| `…-sprint7-notifications-and-lock.sql` | the leave notification templates and `payroll_month_locks` |

Read the header of each before running it. Several state a cost — Sprint 5's cannot restore
`leave_type_id = 11` without first dropping the constraint that fixes the defect, and Sprint 7's
must be reverted together with its code or every payroll save fails on a missing table.

**Applied to `202.47.117.220/hp_erp` only** — the host `.env` points at and the one the whole
engagement ran against. See `00-PROGRESS.md` for the other two connections and why they differ.
