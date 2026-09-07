-- Reversal for Sprint 7 (F-128, F-129). Applied to 202.47.117.220/hp_erp on 2026-09-05.
--
-- WHAT WAS DONE
--   2026_09_05_210000  seeded 3 rows into g2g_notification_template
--   2026_09_05_220000  created payroll_month_locks
--
-- Additive again, like Sprint 6. No pre-existing table was altered, no
-- pre-existing row was changed. Sprint 7 adds three EVENT TYPES to a
-- notification stack that already existed and one new table.
--
-- HOW TO REVERSE
--
--   php artisan migrate:rollback --step=2

-- 1. The month lock table.
DROP TABLE IF EXISTS `payroll_month_locks`;

-- 2. The three templates. Nothing else in g2g_notification_template is touched:
--    the other seven belong to LMS and Talent.
DELETE FROM `g2g_notification_template`
 WHERE `event_type` IN ('leave.submitted', 'leave.decided', 'leave.escalated');

-- 3. Migration records.
DELETE FROM `migrations`
 WHERE `migration` IN (
   '2026_09_05_210000_seed_leave_notification_templates',
   '2026_09_05_220000_create_payroll_month_locks_table'
 );

-- ---------------------------------------------------------------------------
-- WHAT REVERSING COSTS YOU
--
-- THE MONTH LOCK. monthlyPayrollStore's check calls PayrollMonthLock, which
-- queries this table - so dropping it without also reverting the code will
-- make every payroll save fail on a missing table. Revert them TOGETHER:
--
--   git revert  app/Http/Controllers/Payroll/PayrollController.php
--               app/Services/Payroll/PayrollMonthLock.php
--               routes/hrms.php
--
-- Doing so restores the state where a finished, paid month can be silently
-- re-saved with different figures.
--
-- THE NOTIFICATIONS. Deleting the templates does NOT stop the events being
-- emitted - LeaveNotifier still records them to g2g_event, and that is
-- harmless: NotificationComposer returns null when it finds no template and
-- the send is skipped. So this half degrades quietly and correctly. To stop
-- the events as well, revert:
--
--   app/Services/Leave/LeaveNotifier.php               (delete)
--   app/Services/Events/EventCatalogue.php             (the three leave.* entries)
--   app/Services/Events/NotificationDispatcher.php     (NOTIFIES)
--   app/Services/Notifications/RecipientResolver.php   (the three resolvers)
--   app/Http/Controllers/Api/Leave/LeaveRequestApiController.php
--   app/Console/Commands/EscalateOverdueLeaveApprovals.php
--
-- and the module goes back to telling nobody anything: an approver finds out a
-- request exists by opening the screen, and an employee finds out it was
-- decided the same way.
--
-- ---------------------------------------------------------------------------
-- ROWS THIS SPRINT'S CODE WRITES, WHICH ARE NOT SCHEMA
--
-- g2g_notification and g2g_event accumulate rows in normal use. They are
-- history and are NOT cleared by this script - g2g_event is append-only by
-- design (EventRecorder: "no UPDATE, no DELETE; a mistake is corrected by a
-- compensating event"), and g2g_notification rows for a reactor are the record
-- that a real message was really delivered.
--
-- If a rollback genuinely needs them gone, and only then:
--   DELETE FROM g2g_notification WHERE event_type LIKE 'leave.%';
--   DELETE FROM g2g_event_delivery
--    WHERE event_id IN (SELECT id FROM g2g_event WHERE type LIKE 'leave.%');
--   DELETE FROM g2g_event WHERE type LIKE 'leave.%';
--
-- ---------------------------------------------------------------------------
-- EMAIL WAS NOT TOUCHED, AND THAT IS DELIBERATE
--
-- NotificationSender keeps the email channel behind G2G_NOTIFY_EMAIL with
-- three written conditions, one of which is Triz's explicit decision in the
-- turn it happens - 386 real addresses at real companies. HRIT's three event
-- types go through that same sender and inherit the in-app-only default.
-- Nothing in this sprint reads, sets or tests that flag.
