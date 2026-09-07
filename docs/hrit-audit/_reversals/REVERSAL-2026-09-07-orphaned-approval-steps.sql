-- Reversal for 2026_09_07_100000_close_orphaned_approval_steps.
-- Applied to 202.47.117.220/hp_erp on 2026-09-07.
--
-- WHAT WAS DONE
--   26 approval steps whose leave request is soft-deleted (or already decided)
--   were moved from 'pending'/'waiting' to 'skipped'.
--
-- WHY, and why the migration's down() does NOT do this automatically:
--   The hourly `leave:escalate` sweep read hrms_leave_approval_steps alone. The
--   FK to hrms_emp_leaves is ON DELETE CASCADE, which is inert here because the
--   module soft-deletes everywhere -- so steps outlived their requests and the
--   sweep kept stamping the ONE-SHOT escalated_at on them, notifying five HR
--   users each time about requests nobody could open.
--
--   Reopening these steps would put them straight back into that sweep. That is
--   the defect, not a prior state worth restoring, so down() deliberately does
--   nothing and the restore lives here instead -- explicit, and only if somebody
--   genuinely wants it.
--
-- WHAT THESE ROWS ARE:
--   All 26 belong to 17 soft-deleted leave requests, and every one was created
--   by this audit's own probes. The probes soft-deleted their leaves with raw
--   SQL rather than through destroy(), which would have called closeOpenSteps()
--   for them. No tenant data is affected. The probes have since been corrected.
--
-- TO RESTORE (reinstates the escalation noise -- read the above first):

UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 21  ;  -- leave 238, step 1, reporting_manager
UPDATE hrms_leave_approval_steps SET status = 'waiting' WHERE id = 22  ;  -- leave 238, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 33  ;  -- leave 244, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 37  ;  -- leave 246, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 43  ;  -- leave 249, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 49  ;  -- leave 252, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 57  ;  -- leave 257, step 1, reporting_manager
UPDATE hrms_leave_approval_steps SET status = 'waiting' WHERE id = 58  ;  -- leave 257, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 61  ;  -- leave 259, step 1, reporting_manager
UPDATE hrms_leave_approval_steps SET status = 'waiting' WHERE id = 62  ;  -- leave 259, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 65  ;  -- leave 261, step 1, reporting_manager
UPDATE hrms_leave_approval_steps SET status = 'waiting' WHERE id = 66  ;  -- leave 261, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 70  ;  -- leave 263, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 75  ;  -- leave 266, step 1, reporting_manager
UPDATE hrms_leave_approval_steps SET status = 'waiting' WHERE id = 76  ;  -- leave 266, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 79  ;  -- leave 268, step 1, reporting_manager
UPDATE hrms_leave_approval_steps SET status = 'waiting' WHERE id = 80  ;  -- leave 268, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 84  ;  -- leave 270, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 89  ;  -- leave 273, step 1, reporting_manager
UPDATE hrms_leave_approval_steps SET status = 'waiting' WHERE id = 90  ;  -- leave 273, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 95  ;  -- leave 276, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 100 ;  -- leave 279, step 1, reporting_manager
UPDATE hrms_leave_approval_steps SET status = 'waiting' WHERE id = 101 ;  -- leave 279, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 107 ;  -- leave 283, step 2, department_head
UPDATE hrms_leave_approval_steps SET status = 'pending' WHERE id = 112 ;  -- leave 286, step 1, reporting_manager
UPDATE hrms_leave_approval_steps SET status = 'waiting' WHERE id = 113 ;  -- leave 286, step 2, department_head
