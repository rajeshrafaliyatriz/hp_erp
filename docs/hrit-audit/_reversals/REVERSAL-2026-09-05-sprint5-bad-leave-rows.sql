-- Reversal for HRIT Sprint 5's soft-delete of 17 unusable leave rows.
-- Host: 202.47.117.220/hp_erp. Run this to bring every one of them back.
--
-- WHAT WAS REMOVED, and why:
--
--   ids 201-219 (15 rows, tenant 3) - F-94. Their leave_type_id is 11, and no
--   leave type 11 has ever existed; the foreign key allowed it because it named
--   tbluser as the parent, and tbluser 11 does exist. All 15 were created in the
--   same second (2026-06-19 10:25:55) by 4 employees, all still pending, with
--   the comments "Monday pattern" and "Friday/post-payday" - an absenteeism
--   demo seed, not real requests. They reported as "Unassigned" in every leave
--   report and could not be counted against any entitlement.
--
--   ids 12 and 18 - F-123. No from_date, no to_date, no reason. Pending since
--   2026-01-02 and 2026-03-04. Invisible to every date filter, so nobody
--   reviewing a date range would ever have found them to clear them.
--
-- Nothing was destroyed: deleted_at was stamped, and this clears it.
UPDATE hrms_emp_leaves
   SET deleted_at = NULL, deleted_by = NULL
 WHERE id IN (12, 18, 201, 202, 203, 205, 206, 207, 208, 210, 211, 212, 213, 216, 217, 218, 219);

-- The integrity migration (2026_09_05_180000) had to give ids 12 and 18 a date
-- before from_date could be made NOT NULL: ALTER applies to soft-deleted rows
-- too, and STRICT_TRANS_TABLES will not convert their NULL. They were stamped
-- with DATE(created_at). This puts the NULLs back.
UPDATE hrms_emp_leaves SET from_date = NULL, to_date = NULL WHERE id IN (12, 18);

-- ONE THING THIS CANNOT PUT BACK EXACTLY.
--
-- The 15 rows above pointed at leave type 11, which has never existed. The
-- corrected foreign key (2026_09_05_180000) references hrms_leave_types, and a
-- FOREIGN KEY applies to soft-deleted rows too - so they had to be repointed at
-- their tenant's first active leave type before the constraint could go on.
--
-- The UPDATE above restores the ROWS. Their leave_type_id stays repointed,
-- because restoring 11 would now be rejected by the constraint.
--
-- If a byte-for-byte restore is genuinely wanted, drop the constraint first:
--
--   ALTER TABLE hrms_emp_leaves DROP FOREIGN KEY hrms_emp_leaves_leave_type_id_foreign;
--   UPDATE hrms_emp_leaves SET leave_type_id = 11 WHERE id BETWEEN 201 AND 219;
--
-- ...and understand that this reinstates exactly the defect F-94 describes.
